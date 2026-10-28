"""Execute and evaluate the bounded Schemathesis campaign."""

from __future__ import annotations

import argparse
import json
import math
import os
import shlex
import shutil
import statistics
import subprocess
import sys
import time
import uuid
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, Tuple
from urllib.parse import urlsplit

import requests

from .openapi import build_openapi_document
from .request_body import RequestBodyArtifactError, load_request_body
from .reset import RESET_PHASES, run_reset
from .result import apply_suppressions, empty_result, finding_fingerprint, utc_now, validate_result
from .routes import diff_route_documents, select_sut_owned_routes

# HTTP methods that mutate state. An anonymous 2xx on one of these is a differential observation
# (a possible missing permission callback), reported for evaluation review — never a finding.
WRITE_METHODS = {"POST", "PUT", "PATCH", "DELETE"}

# WordPress/Woo REST APIs occasionally use 5xx for an expected domain rejection rather than a
# crash. Keep this allowlist code-specific: suppressing by route/status alone could hide a future
# genuine 500 on the same operation.
EXPECTED_NON_CRASH_ERROR_CODES = {
    "woocommerce_rest_trash_not_supported": "The resource requires force=true for permanent deletion.",
}

# Transport and harness correlation headers are noise in a manual reproduction. Keep the QIT fuzz
# marker, content negotiation, and any SUT-specific headers because they can affect behavior.
REPRODUCTION_OMITTED_HEADERS = {
    "accept-encoding",
    "authorization",
    "connection",
    "content-length",
    "cookie",
    "host",
    "x-qit-api-fuzz-request-id",
    "x-qit-api-fuzz-request-number",
    "x-schemathesis-testcaseid",
}

# Schemathesis normally shrinks failures, but a bounded campaign can stop before its final reduced
# case is emitted. Only spend confirmation time reducing bodies large enough to impede triage.
MINIMIZATION_BODY_THRESHOLD = 256
MINIMIZATION_MAX_ATTEMPTS = 12
MINIMIZATION_FINAL_REPLAYS = 2


def _load_json(path: Path) -> Dict[str, Any]:
    with path.open(encoding="utf-8") as stream:
        value = json.load(stream)
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return value


def _write_json(path: Path, value: Mapping[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def _read_ledger(path: Path) -> List[Dict[str, Any]]:
    records: List[Dict[str, Any]] = []
    if not path.exists():
        return records
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            value = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            records.append(value)
    return records


def _append_ledger(path: Path, record: Mapping[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as stream:
        stream.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")


def _read_counter(path: Path) -> int:
    try:
        return int(path.read_text(encoding="utf-8").strip())
    except (FileNotFoundError, ValueError):
        return 0


def _increment_counter(path: Path) -> int:
    count = _read_counter(path) + 1
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(str(count), encoding="utf-8")
    return count


def _schema_summary(reports: Sequence[Mapping[str, Any]]) -> Dict[str, Any]:
    counts = Counter(str(report.get("usability", "unsupported")) for report in reports)
    return {
        "openapi_version": "3.0.3",
        "complete": counts["complete"],
        "partial": counts["partial"],
        "untyped": counts["untyped"],
        "unsupported": counts["unsupported"],
        "operations": list(reports),
    }


def _schemathesis_binary() -> str:
    candidate = Path(sys.executable).with_name("schemathesis")
    return str(candidate) if candidate.exists() else "schemathesis"


def _admin_auth() -> Tuple[str, str]:
    """Return the administrator credentials shared by generation and confirmation."""

    return (
        os.environ.get("QIT_API_FUZZ_ADMIN_USER", "admin"),
        os.environ.get("QIT_API_FUZZ_ADMIN_PASSWORD", "password"),
    )


def _rest_api_url(site_url: str) -> str:
    """Return the WordPress REST API root for Schemathesis-generated requests."""

    normalized = site_url.rstrip("/")
    return normalized if normalized.endswith("/wp-json") else normalized + "/wp-json"


def _schemathesis_report_completed(path: Path) -> bool:
    """Distinguish expected check failures (exit 1) from an interrupted/broken tool run."""

    event_names: List[str] = []
    if not path.exists():
        return False
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            value = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(value, Mapping):
            event_names.extend(str(name) for name in value)
    return "EngineFinished" in event_names and not {"FatalError", "Interrupted"} & set(event_names)


def _run_profile(
    profile: str,
    schema_path: Path,
    artifacts: Path,
    site_url: str,
    qit: str,
    env_id: str,
    request_budget: int,
    max_examples: int,
    timeout: float,
    run_name: Optional[str] = None,
    phases: str = "fuzzing",
    budget_outcome: str = "request_budget",
    time_outcome: str = "time_budget",
) -> Tuple[str, Optional[int]]:
    report = artifacts / f"schemathesis-{run_name or profile}.ndjson"
    command = [
        _schemathesis_binary(),
        "run",
        str(schema_path),
        "--url",
        _rest_api_url(site_url),
        "--phases",
        phases,
        "--mode",
        "all",
        "--workers",
        "1",
        "--continue-on-failure",
        "--checks",
        "not_a_server_error",
        "--max-examples",
        str(max_examples),
        "--request-timeout",
        "20",
        "--generation-database",
        "none",
        "--report",
        "ndjson",
        "--report-ndjson-path",
        str(report),
        "--output-sanitize",
        "true",
        "--no-color",
    ]
    if profile == "administrator":
        admin_user, admin_password = _admin_auth()
        command.extend(["--auth", f"{admin_user}:{admin_password}"])

    env = os.environ.copy()
    package_root = str(Path(__file__).resolve().parent.parent)
    env["PYTHONPATH"] = package_root + os.pathsep + env.get("PYTHONPATH", "")
    env.update(
        {
            "SCHEMATHESIS_HOOKS": "qit_api_fuzz.hooks",
            "QIT_API_FUZZ_PROFILE": profile,
            "QIT_API_FUZZ_QIT": qit,
            "QIT_API_FUZZ_ENV_ID": env_id,
            "QIT_API_FUZZ_SITE_URL": site_url,
            "QIT_API_FUZZ_LEDGER": str(artifacts / "interactions.jsonl"),
            "QIT_API_FUZZ_COUNTER": str(artifacts / "request-count.txt"),
            "QIT_API_FUZZ_REQUEST_BUDGET": str(request_budget),
            "QIT_API_FUZZ_RESET_MODE": "operation",
        }
    )

    try:
        completed = subprocess.run(command, env=env, timeout=max(1.0, timeout), check=False)
    except subprocess.TimeoutExpired:
        return time_outcome, None

    if _read_counter(artifacts / "request-count.txt") >= request_budget:
        return budget_outcome, completed.returncode
    # Exit code 1 is also Schemathesis's normal result when a check such as
    # `not_a_server_error` finds a defect. The terminal NDJSON events tell that apart from a
    # malformed schema, hook crash, or interrupted run.
    if completed.returncode == 0 or (completed.returncode == 1 and _schemathesis_report_completed(report)):
        return "completed", 0
    return "tool_error", completed.returncode


def _usable_operation_keys(reports: Sequence[Mapping[str, Any]]) -> set[str]:
    return {
        str(report.get("operation", ""))
        for report in reports
        if report.get("usability") != "unsupported" and report.get("operation")
    }


def _confirmation_time_reserve(time_budget_seconds: int) -> int:
    """Reserve enough wall time for clean-state confirmation without starving short test runs."""

    if time_budget_seconds <= 1:
        return 0
    desired = min(180, max(60, time_budget_seconds // 10))
    return min(desired, max(1, time_budget_seconds // 2))


def _reset(qit: str, env_id: str) -> Dict[str, Any]:
    """Restore the clean snapshot and return its structured observation."""

    return run_reset(qit, env_id)


def _poll_events(site_url: str, request_id: str) -> Tuple[List[Dict[str, Any]], Optional[str]]:
    try:
        response = requests.get(
            site_url.rstrip("/") + "/wp-json/qit-api-fuzz/v1/events/" + request_id,
            auth=_admin_auth(),
            headers={
                "X-QIT-API-Fuzz": "1",
                "X-QIT-API-Fuzz-Request-ID": request_id,
            },
            timeout=10,
        )
        if not response.ok:
            return [], f"instrumentation_http_{response.status_code}"
        value = response.json()
        if not isinstance(value, list):
            return [], "instrumentation_invalid_payload"
        return value, None
    except (requests.RequestException, ValueError) as error:
        return [], type(error).__name__


def _body(record: Mapping[str, Any], artifact_root: Optional[Path] = None) -> Optional[bytes]:
    return load_request_body(record, artifact_root)


def _replay(
    record: Mapping[str, Any],
    qit: str,
    env_id: str,
    site_url: str,
    artifact_root: Optional[Path] = None,
) -> Dict[str, Any]:
    try:
        body = _body(record, artifact_root)
    except RequestBodyArtifactError as error:
        return {
            "status": "infrastructure_error",
            "reproduced": None,
            "request_attempted": False,
            "reason": error.reason,
        }

    reset_start = time.monotonic()
    reset_result = _reset(qit, env_id)
    reset_seconds = time.monotonic() - reset_start
    if isinstance(reset_result, Mapping):
        reset = dict(reset_result)
        reset_seconds = float(reset.get("seconds", reset_seconds))
    else:
        # Preserve compatibility with direct callers and older tests that returned an optional
        # error string before env:reset exposed a structured JSON contract.
        reset = {
            "status": "completed" if reset_result is None else "infrastructure_error",
            "reason": reset_result,
            "reset_performed": True,
            "seconds": reset_seconds,
            "cli_seconds": 0.0,
            "caller_overhead_seconds": reset_seconds,
            "strategy": "unknown",
            "failed_phase": None,
            "message": "",
            "phases": {},
        }
    reset_performed = bool(reset.get("reset_performed", True))
    if artifact_root is not None:
        _append_ledger(
            artifact_root / "interactions.jsonl",
            {
                "type": "reset",
                "scope": "confirmation",
                "profile": str(record.get("profile", "unknown")),
                "operation": str(record.get("operation", "")),
                **reset,
            },
        )
    if reset.get("status") != "completed":
        return {
            "status": "infrastructure_error",
            "reproduced": None,
            "request_attempted": False,
            "reset_performed": reset_performed,
            "reset_seconds": reset_seconds,
            "reset": reset,
            "reason": reset.get("reason", "clean_state_restore_failed"),
        }

    request_id = str(uuid.uuid4())
    headers = {
        str(name): str(value)
        for name, value in (record.get("headers") or {}).items()
        if str(name).lower() not in {"authorization", "cookie", "content-length", "host"}
    }
    headers["X-QIT-API-Fuzz"] = "1"
    headers["X-QIT-API-Fuzz-Request-ID"] = request_id
    auth = _admin_auth() if record.get("profile") == "administrator" else None
    try:
        response = requests.request(
            str(record.get("method", "GET")),
            str(record.get("url", "")),
            headers=headers,
            data=body,
            auth=auth,
            timeout=20,
        )
        events, instrumentation_error = _poll_events(site_url, request_id)
        if instrumentation_error is not None:
            return {
                "status": "infrastructure_error",
                "reproduced": None,
                "request_attempted": True,
                "reset_performed": reset_performed,
                "reset_seconds": reset_seconds,
                "reset": reset,
                "response_status": response.status_code,
                "reason": instrumentation_error,
            }
        fatal = any(event.get("type") == "php_fatal" for event in events)
        return {
            "status": "completed",
            "reproduced": response.status_code >= 500 or fatal,
            "request_attempted": True,
            "reset_performed": reset_performed,
            "reset_seconds": reset_seconds,
            "reset": reset,
            "response_status": response.status_code,
            "events": events,
        }
    except requests.RequestException as error:
        return {
            "status": "infrastructure_error",
            "reproduced": None,
            "request_attempted": True,
            "reset_performed": reset_performed,
            "reset_seconds": reset_seconds,
            "reset": reset,
            "reason": type(error).__name__,
        }


def _candidate_key(record: Mapping[str, Any]) -> Tuple[str, str, str, str]:
    events = record.get("instrumentation_events") or []
    fatal = next((event for event in events if event.get("type") == "php_fatal"), {})
    return (
        str(record.get("method", "")),
        str(record.get("route", "")),
        str(fatal.get("error_file", "")),
        str(fatal.get("error_line", "")),
    )


def _candidate_size(record: Mapping[str, Any]) -> int:
    body_size = record.get("body_size")
    if not isinstance(body_size, int):
        body_size = len(str(record.get("body", ""))) + len(str(record.get("body_base64", "")))
    return len(str(record.get("url", ""))) + body_size


def _json_bytes(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=True, separators=(",", ":")).encode("utf-8")


def _direct_json_reductions(value: Any) -> Iterable[Any]:
    if isinstance(value, dict):
        if value:
            yield {}
        if len(value) > 1:
            for key, child in value.items():
                yield {key: child}
    elif isinstance(value, list):
        if value:
            yield []
        if len(value) > 1:
            for child in value:
                yield [child]
    elif isinstance(value, str):
        if value:
            yield ""
        if value not in {"", "a"}:
            yield "a"
    elif isinstance(value, bool):
        if value:
            yield False
    elif isinstance(value, (int, float)):
        for replacement in (0, 1, -1):
            if value != replacement:
                yield replacement


def _json_reduction_candidates(value: Any) -> List[Any]:
    """Return deterministic, strictly smaller structural reductions of a JSON value."""

    candidates = list(_direct_json_reductions(value))
    if isinstance(value, dict):
        for key, child in value.items():
            for reduced_child in _direct_json_reductions(child):
                candidate = dict(value)
                candidate[key] = reduced_child
                candidates.append(candidate)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            for reduced_child in _direct_json_reductions(child):
                candidate = list(value)
                candidate[index] = reduced_child
                candidates.append(candidate)

    current_size = len(_json_bytes(value))
    unique: Dict[bytes, Any] = {}
    for candidate in candidates:
        encoded = _json_bytes(candidate)
        if len(encoded) < current_size:
            unique.setdefault(encoded, candidate)
    return [unique[encoded] for encoded in sorted(unique, key=lambda item: (len(item), item))]


def _record_with_json_body(record: Mapping[str, Any], value: Any) -> Dict[str, Any]:
    candidate = dict(record)
    for field in ("body_base64", "body_file", "body_sha256", "body_size"):
        candidate.pop(field, None)
    candidate["body"] = _json_bytes(value).decode("utf-8")
    return candidate


def _fatal_identity(events: Iterable[Mapping[str, Any]]) -> Optional[Tuple[str, str, str]]:
    fatal = next((event for event in events if event.get("type") == "php_fatal"), None)
    if fatal is None:
        return None
    return (
        str(fatal.get("error_type", "")),
        str(fatal.get("error_file", "")),
        str(fatal.get("error_line", "")),
    )


def _replay_matches_finding(record: Mapping[str, Any], replay: Mapping[str, Any]) -> bool:
    if replay.get("status") != "completed" or not replay.get("reproduced"):
        return False
    expected_fatal = _fatal_identity(record.get("instrumentation_events") or [])
    replay_fatal = _fatal_identity(replay.get("events") or [])
    if expected_fatal is not None:
        return replay_fatal == expected_fatal
    return replay_fatal is None and int(replay.get("response_status", 0)) >= 500


def _account_replay(
    replay: Mapping[str, Any],
    counter_path: Optional[Path],
    stats: Optional[Dict[str, Any]],
) -> None:
    observation = replay.get("reset")
    reset_attempted = isinstance(observation, Mapping) or bool(replay.get("reset_performed"))
    if stats is not None and reset_attempted:
        stats["reset_count"] = stats.get("reset_count", 0) + 1
        stats["reset_seconds"] = stats.get("reset_seconds", 0.0) + float(
            replay.get("reset_seconds", 0.0)
        )
        if isinstance(observation, Mapping):
            stats.setdefault("reset_observations", []).append(dict(observation))
    if counter_path is not None and replay.get("request_attempted") is not False:
        _increment_counter(counter_path)


def _percentile(values: Sequence[float], percentile: float) -> float:
    """Return the nearest-rank percentile for a non-empty sequence."""

    if not values:
        return 0.0
    ordered = sorted(values)
    rank = max(1, math.ceil(percentile * len(ordered)))
    return float(ordered[min(len(ordered), rank) - 1])


def _legacy_reset_observation(record: Mapping[str, Any]) -> Dict[str, Any]:
    seconds = float(record.get("reset_seconds", 0.0))
    phases = record.get("reset_phases")
    return {
        "status": "completed",
        "reason": None,
        "reset_performed": True,
        "seconds": seconds,
        "cli_seconds": seconds,
        "caller_overhead_seconds": 0.0,
        "strategy": str(record.get("reset_strategy", "unknown")),
        "failed_phase": None,
        "message": "",
        "phases": dict(phases) if isinstance(phases, Mapping) else {},
    }


def _reset_overhead(observations: Sequence[Mapping[str, Any]]) -> Dict[str, Any]:
    """Aggregate every reset attempt, including failed and protocol-error attempts."""

    reset_seconds = [max(0.0, float(item.get("seconds", 0.0))) for item in observations]
    strategies = Counter(str(item.get("strategy", "unknown")) for item in observations)
    known_strategies = {name for name in strategies if name != "unknown"}
    strategy = next(iter(known_strategies)) if len(known_strategies) == 1 else (
        "mixed" if len(known_strategies) > 1 else "unknown"
    )

    phase_summaries: Dict[str, Dict[str, Any]] = {}
    for name in (*RESET_PHASES, "caller_overhead"):
        seconds_values: List[float] = []
        status_counts = Counter()
        for observation in observations:
            if name == "caller_overhead":
                phases = observation.get("phases")
                has_cli_timing = isinstance(phases, Mapping) and all(
                    isinstance(phases.get(phase_name), Mapping)
                    for phase_name in RESET_PHASES
                )
                status = "completed" if has_cli_timing else "not_started"
                seconds = (
                    max(0.0, float(observation.get("caller_overhead_seconds", 0.0)))
                    if has_cli_timing
                    else 0.0
                )
            else:
                phases = observation.get("phases")
                phase = phases.get(name) if isinstance(phases, Mapping) else None
                status = str(phase.get("status", "not_started")) if isinstance(phase, Mapping) else "not_started"
                seconds = max(0.0, float(phase.get("seconds", 0.0))) if isinstance(phase, Mapping) else 0.0
            status_counts[status] += 1
            seconds_values.append(seconds)
        phase_summaries[name] = {
            "count": len(seconds_values),
            "completed_count": status_counts["completed"],
            "skipped_count": status_counts["skipped"],
            "failed_count": status_counts["failed"],
            "not_started_count": status_counts["not_started"],
            "seconds_total": round(sum(seconds_values), 3),
            "seconds_median": round(statistics.median(seconds_values), 3) if seconds_values else 0.0,
            "seconds_p95": round(_percentile(seconds_values, 0.95), 3),
        }

    timed_phase_names = [
        name for name, summary in phase_summaries.items() if summary["seconds_total"] > 0
    ]
    limiting_phase = (
        max(timed_phase_names, key=lambda name: phase_summaries[name]["seconds_total"])
        if timed_phase_names
        else None
    )
    return {
        "reset_count": len(observations),
        "reset_seconds_total": round(sum(reset_seconds), 3),
        "reset_seconds_median": round(statistics.median(reset_seconds), 3) if reset_seconds else 0.0,
        "reset_seconds_p95": round(_percentile(reset_seconds, 0.95), 3),
        "reset_strategy": strategy,
        "reset_strategies": dict(sorted(strategies.items())),
        "reset_limiting_phase": limiting_phase,
        "reset_phases": phase_summaries,
    }


def _profile_depth(
    interactions: Sequence[Mapping[str, Any]],
    usable_keys: set[str],
) -> Dict[str, Any]:
    counts = Counter(
        str(record.get("operation"))
        for record in interactions
        if str(record.get("operation", "")) in usable_keys
    )
    examples = [counts[operation] for operation in sorted(usable_keys)]
    return {
        "examples_total": sum(examples),
        "usable_operations": len(examples),
        "min_examples_per_operation": min(examples) if examples else 0,
        "median_examples_per_operation": round(statistics.median(examples), 3) if examples else 0.0,
        "p95_examples_per_operation": round(_percentile(examples, 0.95), 3),
        "max_examples_per_operation": max(examples) if examples else 0,
    }


def _minimize_json_record(
    record: Mapping[str, Any],
    qit: str,
    env_id: str,
    site_url: str,
    artifact_root: Optional[Path],
    counter_path: Optional[Path],
    request_budget: int,
    deadline: Optional[float],
    stats: Optional[Dict[str, Any]],
) -> Tuple[Mapping[str, Any], Dict[str, Any]]:
    """Reduce a large JSON body and retain it only after two matching clean-state replays."""

    try:
        original_body = _body(record, artifact_root)
    except RequestBodyArtifactError as error:
        return record, {"status": "unavailable", "reason": error.reason, "attempts": 0}
    if original_body is None or len(original_body) <= MINIMIZATION_BODY_THRESHOLD:
        size = len(original_body or b"")
        return record, {
            "status": "not_needed",
            "original_bytes": size,
            "minimized_bytes": size,
            "attempts": 0,
        }
    try:
        value = json.loads(original_body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return record, {
            "status": "not_applicable",
            "reason": "body_is_not_json",
            "original_bytes": len(original_body),
            "minimized_bytes": len(original_body),
            "attempts": 0,
        }

    current_value = value
    current_record: Mapping[str, Any] = record
    attempts = 0
    stop_reason = "local_minimum"
    while attempts < MINIMIZATION_MAX_ATTEMPTS:
        accepted = False
        candidates = _json_reduction_candidates(current_value)
        if not candidates:
            break
        for candidate_value in candidates:
            if attempts >= MINIMIZATION_MAX_ATTEMPTS:
                stop_reason = "attempt_budget"
                break
            if counter_path is not None and _read_counter(counter_path) >= request_budget - 2:
                stop_reason = "request_budget"
                break
            if deadline is not None and time.monotonic() >= deadline - 10:
                stop_reason = "time_budget"
                break

            candidate_record = _record_with_json_body(record, candidate_value)
            replay = _replay(candidate_record, qit, env_id, site_url, artifact_root)
            _account_replay(replay, counter_path, stats)
            attempts += 1
            if _replay_matches_finding(record, replay):
                current_value = candidate_value
                current_record = candidate_record
                accepted = True
                break
        if not accepted:
            break

    original_size = len(original_body)
    minimized_size = len(_json_bytes(current_value))
    if current_record is record:
        return record, {
            "status": "not_reduced",
            "reason": stop_reason,
            "original_bytes": original_size,
            "minimized_bytes": original_size,
            "attempts": attempts,
        }

    verification: List[Mapping[str, Any]] = []
    verification_attempts = 0
    while len(verification) < MINIMIZATION_FINAL_REPLAYS and verification_attempts < 4:
        if counter_path is not None and _read_counter(counter_path) >= request_budget:
            stop_reason = "request_budget"
            break
        if deadline is not None and time.monotonic() >= deadline:
            stop_reason = "time_budget"
            break
        replay = _replay(current_record, qit, env_id, site_url, artifact_root)
        _account_replay(replay, counter_path, stats)
        verification_attempts += 1
        if replay.get("status") == "infrastructure_error":
            continue
        verification.append(replay)
        if not _replay_matches_finding(record, replay):
            break

    verified = (
        len(verification) == MINIMIZATION_FINAL_REPLAYS
        and all(_replay_matches_finding(record, replay) for replay in verification)
    )
    if not verified:
        return record, {
            "status": "verification_failed",
            "reason": stop_reason,
            "original_bytes": original_size,
            "minimized_bytes": original_size,
            "attempts": attempts + verification_attempts,
        }

    return current_record, {
        "status": "completed",
        "reason": stop_reason,
        "original_bytes": original_size,
        "minimized_bytes": minimized_size,
        "attempts": attempts + verification_attempts,
        "clean_state_replays": MINIMIZATION_FINAL_REPLAYS,
        "reproduced": MINIMIZATION_FINAL_REPLAYS,
    }


def _curl(record: Mapping[str, Any]) -> str:
    lines = [f"curl --request {shlex.quote(str(record.get('method', 'GET')))}"]
    if record.get("profile") == "administrator":
        lines.append('--user "${QIT_ADMIN_USER}:${QIT_ADMIN_PASSWORD}"')
    for name, value in sorted((record.get("headers") or {}).items()):
        if str(name).lower() in REPRODUCTION_OMITTED_HEADERS:
            continue
        lines.append(f"--header {shlex.quote(f'{name}: {value}')}")
    if isinstance(record.get("body_file"), str):
        lines.append(f"--data-binary {shlex.quote('@' + record['body_file'])}")
    else:
        body = _body(record)
        if body is not None:
            lines.append(f"--data-binary {shlex.quote(body.decode('utf-8', errors='replace'))}")

    parsed_url = urlsplit(str(record.get("url", "")))
    relative_url = parsed_url.path or "/"
    if parsed_url.query:
        relative_url += "?" + parsed_url.query
    if parsed_url.fragment:
        relative_url += "#" + parsed_url.fragment
    escaped_url = (
        relative_url.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("$", "\\$")
        .replace("`", "\\`")
    )
    lines.append(f'"${{QIT_SITE_URL}}{escaped_url}"')

    return " \\\n  ".join(lines)


def _is_sut_file(path: str, sut_slug: str) -> bool:
    normalized = path.replace("\\", "/").lower()
    slug = sut_slug.lower()
    return f"/wp-content/plugins/{slug}/" in normalized or (
        slug in {"woocommerce", "wporg-woocommerce"} and "/wp-content/plugins/woocommerce/" in normalized
    )


def _fault_origin(path: str, sut_slug: str) -> str:
    normalized = path.replace("\\", "/").lower()
    if _is_sut_file(path, sut_slug):
        return "sut"
    if "/wp-content/plugins/woocommerce/" in normalized:
        return "woocommerce"
    if "/wp-includes/" in normalized or "/wp-admin/" in normalized:
        return "wordpress"
    return "unknown"


def _confirmed_findings(
    records: Sequence[Mapping[str, Any]],
    qit: str,
    env_id: str,
    site_url: str,
    sut_slug: str,
    counter_path: Optional[Path] = None,
    request_budget: int = 2500,
    deadline: Optional[float] = None,
    stats: Optional[Dict[str, Any]] = None,
) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    candidates: Dict[Tuple[str, str, str, str], Mapping[str, Any]] = {}
    anomalies: List[Dict[str, Any]] = []
    ignored_responses: set[Tuple[str, str, str]] = set()
    for record in records:
        if record.get("type") != "interaction":
            continue
        events = record.get("instrumentation_events") or []
        has_fatal = any(event.get("type") == "php_fatal" for event in events)
        if int(record.get("response_status", 0)) < 500 and not has_fatal:
            continue
        response_error_code = str(record.get("response_error_code", ""))
        if not has_fatal and response_error_code in EXPECTED_NON_CRASH_ERROR_CODES:
            ignored_key = (
                str(record.get("method", "")),
                str(record.get("route", "")),
                response_error_code,
            )
            if ignored_key not in ignored_responses:
                ignored_responses.add(ignored_key)
                anomalies.append(
                    {
                        "type": "expected_5xx_response_ignored",
                        "method": record.get("method", ""),
                        "route": record.get("route", ""),
                        "response_status": int(record.get("response_status", 0)),
                        "response_error_code": response_error_code,
                        "reason": EXPECTED_NON_CRASH_ERROR_CODES[response_error_code],
                    }
                )
            continue
        key = _candidate_key(record)
        if key not in candidates or _candidate_size(record) < _candidate_size(candidates[key]):
            candidates[key] = record

    findings: List[Dict[str, Any]] = []
    for record in candidates.values():
        replays: List[Dict[str, Any]] = []
        infrastructure_errors: List[Dict[str, Any]] = []
        attempts = 0
        # Infrastructure failures do not count as clean-state replays. Allow two bounded retries
        # so one transient reset or transport failure cannot erase a deterministic SUT fatal.
        while len(replays) < 2 and attempts < 4:
            budget_exhausted = counter_path is not None and _read_counter(counter_path) >= request_budget
            time_exhausted = deadline is not None and time.monotonic() >= deadline
            if budget_exhausted or time_exhausted:
                anomalies.append(
                    {
                        "type": "confirmation_incomplete",
                        "method": record.get("method", ""),
                        "route": record.get("route", ""),
                        "reason": "request_budget" if budget_exhausted else "time_budget",
                        "completed_replays": len(replays),
                        "required_replays": 2,
                        "attempts": attempts,
                    }
                )
                break
            artifact_root = counter_path.parent if counter_path is not None else None
            replay = _replay(record, qit, env_id, site_url, artifact_root)
            attempts += 1
            _account_replay(replay, counter_path, stats)
            if replay.get("status") == "infrastructure_error":
                infrastructure_errors.append(replay)
                continue
            replays.append(replay)

        if len(replays) != 2:
            if infrastructure_errors:
                anomalies.append(
                    {
                        "type": "confirmation_infrastructure_error",
                        "method": record.get("method", ""),
                        "route": record.get("route", ""),
                        "completed_replays": len(replays),
                        "required_replays": 2,
                        "attempts": attempts,
                        "errors": [error.get("reason", "unknown") for error in infrastructure_errors],
                    }
                )
            continue
        reproduced = sum(bool(replay.get("reproduced")) for replay in replays)
        if reproduced != 2:
            anomalies.append(
                {
                    "type": "non_reproducible_candidate",
                    "method": record.get("method", ""),
                    "route": record.get("route", ""),
                    "reproduced": reproduced,
                    "clean_state_replays": 2,
                    "attempts": attempts,
                    "infrastructure_retries": [
                        error.get("reason", "unknown") for error in infrastructure_errors
                    ],
                }
            )
            continue

        artifact_root = counter_path.parent if counter_path is not None else None
        minimized_record, minimization = _minimize_json_record(
            record,
            qit,
            env_id,
            site_url,
            artifact_root,
            counter_path,
            request_budget,
            deadline,
            stats,
        )
        original_events = record.get("instrumentation_events") or []
        replay_events = [event for replay in replays for event in replay.get("events", [])]
        fatal = next(
            (event for event in [*original_events, *replay_events] if event.get("type") == "php_fatal"),
            {},
        )
        error_file = str(fatal.get("error_file", ""))
        finding: Dict[str, Any] = {
            "finding_type": "php_fatal" if fatal else "sut_5xx",
            "is_sut_attributed": _is_sut_file(error_file, sut_slug),
            "route_owner": "sut",
            "fault_origin": _fault_origin(error_file, sut_slug),
            "method": record.get("method", ""),
            "route": record.get("route", ""),
            "redacted_request": _curl(minimized_record),
            "response_status": record.get("response_status", 0),
            "error_type": fatal.get("error_type", ""),
            "error_message": fatal.get("error_message", ""),
            "error_file": error_file,
            "error_line": fatal.get("error_line", 0),
            "backtrace": [],
            "auth_profile": record.get("profile", "unknown"),
            "confirmation": {
                "clean_state_replays": 2,
                "reproduced": reproduced,
                "attempts": attempts,
                "infrastructure_retries": [
                    error.get("reason", "unknown") for error in infrastructure_errors
                ],
                "responses": [replay.get("response_status", 0) for replay in replays],
            },
            "minimization": minimization,
        }
        if minimization.get("status") == "completed":
            finding["original_redacted_request"] = _curl(record)
        finding["fingerprint"] = finding_fingerprint(finding)
        findings.append(finding)
    return findings, anomalies


def run_campaign(
    baseline_routes: Path,
    sut_routes: Path,
    output: Path,
    artifacts: Path,
    site_url: str,
    qit: str,
    env_id: str,
    sut: Mapping[str, Any],
    environment: Mapping[str, Any],
    suppressions_file: Optional[Path] = None,
    request_budget: int = 2500,
    time_budget_seconds: int = 1200,
) -> Dict[str, Any]:
    started_monotonic = time.monotonic()
    result = empty_result(sut, environment)
    result["campaign"]["started_at"] = utc_now()
    result["campaign"]["request_budget"] = request_budget
    result["campaign"]["time_budget_seconds"] = time_budget_seconds
    artifacts.mkdir(parents=True, exist_ok=True)
    request_bodies = artifacts / "request-bodies"
    if request_bodies.exists():
        shutil.rmtree(request_bodies)
    request_bodies.mkdir(parents=True)
    for name in ("interactions.jsonl", "request-count.txt", "openapi-added.json", "openapi-modified.json"):
        path = artifacts / name
        if path.exists():
            path.unlink()
    for path in artifacts.glob("schemathesis-*.ndjson"):
        path.unlink()

    try:
        baseline = _load_json(baseline_routes)
        with_sut = _load_json(sut_routes)
        diff = diff_route_documents(baseline, with_sut)
        selection = select_sut_owned_routes(diff, str(sut.get("slug", "")))
        conversion = build_openapi_document(selection.targeted, _rest_api_url(site_url))
        result["discovery"] = selection.as_dict(diff)
        result["schema"] = _schema_summary(conversion.operation_reports)
        schema_path = artifacts / "openapi.json"
        _write_json(schema_path, conversion.document)

        result["artifacts"]["items"] = [
            {"name": "openapi", "path": "openapi.json"},
            {"name": "interaction_ledger", "path": "interactions.jsonl"},
            {"name": "request_bodies", "path": "request-bodies/"},
        ]

        if not selection.targeted:
            result["campaign"]["state"] = "not_applicable"
            result["campaign"]["stop_reason"] = "no_sut_owned_rest_operations"
            return result

        if conversion.usable_operation_count == 0:
            result["campaign"]["state"] = "unavailable"
            result["campaign"]["stop_reason"] = "no_usable_schema_operations"
            result["errors"].append(
                {
                    "type": "schema_conversion_error",
                    "message": "SUT operations were discovered, but none could be represented safely in OpenAPI.",
                }
            )
            return result

        usable_keys = _usable_operation_keys(conversion.operation_reports)
        usable_count = len(usable_keys)
        operation_groups: List[Tuple[str, Path, int]] = []
        for group_name, changes in (
            ("added", selection.owned_added),
            ("modified", selection.owned_modified),
        ):
            operations = [change.operation for change in changes if change.operation.key in usable_keys]
            if not operations:
                continue
            group_conversion = build_openapi_document(operations, _rest_api_url(site_url))
            group_schema_path = artifacts / f"openapi-{group_name}.json"
            _write_json(group_schema_path, group_conversion.document)
            operation_groups.append((group_name, group_schema_path, group_conversion.usable_operation_count))
            result["artifacts"]["items"].append(
                {"name": f"openapi_{group_name}", "path": group_schema_path.name}
            )

        confirmation_reserve = min(200, max(20, request_budget // 10))
        generation_budget = max(1, request_budget - confirmation_reserve)
        confirmation_time_reserve = _confirmation_time_reserve(time_budget_seconds)
        generation_time_budget = max(1, time_budget_seconds - confirmation_time_reserve)
        generation_deadline = started_monotonic + generation_time_budget
        profiles = ("anonymous", "administrator")
        profile_request_budgets = {
            "anonymous": (generation_budget + 1) // 2,
            "administrator": generation_budget // 2,
        }
        profile_time_budgets = {
            profile: generation_time_budget / len(profiles) for profile in profiles
        }
        profile_requests = {profile: 0 for profile in profiles}
        profile_seconds = {profile: 0.0 for profile in profiles}
        profile_outcomes: Dict[str, List[str]] = {profile: [] for profile in profiles}
        breadth_outcomes: Dict[str, Dict[str, str]] = {profile: {} for profile in profiles}
        scheduling_stages: List[Dict[str, Any]] = []
        generation_start = time.monotonic()

        # Breadth comes first and added routes come before modified shared routes. Each profile gets
        # an independent request/time quota so a slow anonymous stage cannot prevent administrator
        # coverage. Schemathesis's expensive coverage phase is deliberately excluded here: one fuzz
        # example per operation establishes breadth before any operation receives depth.
        for group_name, group_schema_path, group_usable_count in operation_groups:
            for profile in profiles:
                before_count = _read_counter(artifacts / "request-count.txt")
                remaining_requests = profile_request_budgets[profile] - profile_requests[profile]
                remaining_profile_time = profile_time_budgets[profile] - profile_seconds[profile]
                remaining_global_time = generation_deadline - time.monotonic()
                stage_name = f"{profile}-{group_name}-breadth"
                if remaining_requests <= 0:
                    outcome, return_code, elapsed = "profile_request_budget", None, 0.0
                elif remaining_profile_time <= 0 or remaining_global_time <= 0:
                    outcome, return_code, elapsed = "profile_time_budget", None, 0.0
                else:
                    stage_start = time.monotonic()
                    outcome, return_code = _run_profile(
                        profile,
                        group_schema_path,
                        artifacts,
                        site_url,
                        qit,
                        env_id,
                        before_count + remaining_requests,
                        1,
                        min(remaining_profile_time, remaining_global_time),
                        stage_name,
                        "fuzzing",
                        "profile_request_budget",
                        "profile_time_budget",
                    )
                    elapsed = time.monotonic() - stage_start
                after_count = _read_counter(artifacts / "request-count.txt")
                requests_used = max(0, after_count - before_count)
                profile_requests[profile] += requests_used
                profile_seconds[profile] += elapsed
                profile_outcomes[profile].append(outcome)
                breadth_outcomes[profile][group_name] = outcome
                scheduling_stages.append(
                    {
                        "name": stage_name,
                        "profile": profile,
                        "operation_group": group_name,
                        "purpose": "breadth",
                        "usable_operations": group_usable_count,
                        "max_examples": 1,
                        "requests_executed": requests_used,
                        "seconds": round(elapsed, 3),
                        "outcome": outcome,
                    }
                )
                result["artifacts"]["items"].append(
                    {"name": f"schemathesis_{stage_name}", "path": f"schemathesis-{stage_name}.ndjson"}
                )
                if return_code not in (None, 0) and outcome == "tool_error":
                    result["errors"].append(
                        {
                            "type": "schemathesis_error",
                            "profile": profile,
                            "stage": stage_name,
                            "return_code": return_code,
                        }
                    )

        # Once both added/modified breadth stages for a profile complete, spend its remaining quota
        # on multiple examples per operation. This is the fuzzing-depth portion of the campaign; it
        # can stop at its profile quota without stealing the other profile's opportunity to run.
        for profile in profiles:
            if any(outcome != "completed" for outcome in breadth_outcomes[profile].values()):
                continue
            before_count = _read_counter(artifacts / "request-count.txt")
            remaining_requests = profile_request_budgets[profile] - profile_requests[profile]
            remaining_profile_time = profile_time_budgets[profile] - profile_seconds[profile]
            remaining_global_time = generation_deadline - time.monotonic()
            max_examples = min(25, remaining_requests // usable_count)
            if max_examples < 2 or remaining_profile_time <= 0 or remaining_global_time <= 0:
                continue
            stage_name = f"{profile}-depth"
            stage_start = time.monotonic()
            outcome, return_code = _run_profile(
                profile,
                schema_path,
                artifacts,
                site_url,
                qit,
                env_id,
                before_count + remaining_requests,
                max_examples,
                min(remaining_profile_time, remaining_global_time),
                stage_name,
                "fuzzing",
                "profile_request_budget",
                "profile_time_budget",
            )
            elapsed = time.monotonic() - stage_start
            after_count = _read_counter(artifacts / "request-count.txt")
            requests_used = max(0, after_count - before_count)
            profile_requests[profile] += requests_used
            profile_seconds[profile] += elapsed
            profile_outcomes[profile].append(outcome)
            scheduling_stages.append(
                {
                    "name": stage_name,
                    "profile": profile,
                    "operation_group": "all",
                    "purpose": "depth",
                    "usable_operations": usable_count,
                    "max_examples": max_examples,
                    "requests_executed": requests_used,
                    "seconds": round(elapsed, 3),
                    "outcome": outcome,
                }
            )
            result["artifacts"]["items"].append(
                {"name": f"schemathesis_{stage_name}", "path": f"schemathesis-{stage_name}.ndjson"}
            )
            if return_code not in (None, 0) and outcome == "tool_error":
                result["errors"].append(
                    {
                        "type": "schemathesis_error",
                        "profile": profile,
                        "stage": stage_name,
                        "return_code": return_code,
                    }
                )
        generation_seconds = time.monotonic() - generation_start
        result["campaign"]["scheduling"] = {
            "strategy": "sut_added_then_modified_breadth_first",
            "generation_phases": ["fuzzing"],
            "confirmation_request_reserve": confirmation_reserve,
            "confirmation_time_reserve_seconds": confirmation_time_reserve,
            "profiles": {
                profile: {
                    "request_budget": profile_request_budgets[profile],
                    "time_budget_seconds": round(profile_time_budgets[profile], 3),
                    "requests_executed": profile_requests[profile],
                    "seconds": round(profile_seconds[profile], 3),
                    "outcomes": profile_outcomes[profile],
                }
                for profile in profiles
            },
            "stages": scheduling_stages,
        }

        records = _read_ledger(artifacts / "interactions.jsonl")
        interactions = [record for record in records if record.get("type") == "interaction"]
        confirmation_stats: Dict[str, Any] = {
            "reset_count": 0,
            "reset_seconds": 0.0,
            "reset_observations": [],
        }
        confirmation_start = time.monotonic()
        findings, anomalies = _confirmed_findings(
            interactions,
            qit,
            env_id,
            site_url,
            str(sut.get("slug", "")),
            artifacts / "request-count.txt",
            request_budget,
            started_monotonic + time_budget_seconds,
            confirmation_stats,
        )
        confirmation_seconds = time.monotonic() - confirmation_start
        result["findings"] = findings
        result["anomalies"].extend(anomalies)
        result["anomalies"].extend(record for record in records if record.get("type") == "isolation_error")

        # Differential observation: an anonymous request that reached a write callback and got a 2xx
        # may indicate a missing permission check. Reported for evaluation review, not scored.
        seen_anonymous_writes: set = set()
        for record in interactions:
            if record.get("profile") != "anonymous":
                continue
            method = str(record.get("method", "")).upper()
            status = int(record.get("response_status", 0))
            if method not in WRITE_METHODS or not 200 <= status < 300:
                continue
            reached = any(
                event.get("type") == "rest_callback_reached"
                for event in record.get("instrumentation_events") or []
            )
            route = str(record.get("route", ""))
            if not reached or (method, route) in seen_anonymous_writes:
                continue
            seen_anonymous_writes.add((method, route))
            result["anomalies"].append(
                {
                    "type": "anonymous_write_accepted",
                    "method": method,
                    "route": route,
                    "response_status": status,
                    "operation": str(record.get("operation", "")),
                }
            )

        # Prefer the independent reset ledger so failed attempts are retained. Fall back to the
        # interaction annotations when reading ledgers written by the previous runner.
        generation_observations = [
            record for record in records if record.get("type") == "reset"
        ]
        if not generation_observations:
            generation_observations = [
                _legacy_reset_observation(record)
                for record in interactions
                if record.get("reset_performed")
            ]
        confirmation_observations = list(confirmation_stats.get("reset_observations", []))
        if not confirmation_observations and confirmation_stats.get("reset_count"):
            count = int(confirmation_stats["reset_count"])
            total = float(confirmation_stats.get("reset_seconds", 0.0))
            confirmation_observations = [
                _legacy_reset_observation({"reset_seconds": total / count})
                for _ in range(count)
            ]
        reset_observations = generation_observations + confirmation_observations
        result["campaign"]["overhead"] = {
            "generation_seconds": round(generation_seconds, 3),
            "confirmation_seconds": round(confirmation_seconds, 3),
            **_reset_overhead(reset_observations),
        }
        result["campaign"]["isolation"].update(
            {
                "database": "restored_before_each_generated_operation_batch_and_before_each_replay",
                "generation_reset_strategy": "operation_batch",
            }
        )
        result["campaign"]["isolation"].pop("generation_reset_every", None)

        configured_suppressions: Sequence[Mapping[str, Any]] = []
        if suppressions_file and suppressions_file.exists():
            loaded = json.loads(suppressions_file.read_text(encoding="utf-8"))
            if isinstance(loaded, list):
                configured_suppressions = loaded
        result["suppressions"] = apply_suppressions(result["findings"], configured_suppressions)

        profile_operation_keys: Dict[str, set[str]] = {}
        profile_depth: Dict[str, Dict[str, Any]] = {}
        for profile in profiles:
            profile_records = [record for record in interactions if record.get("profile") == profile]
            reached = sum(
                any(
                    event.get("type") == "rest_callback_reached"
                    for event in record.get("instrumentation_events") or []
                )
                for record in profile_records
            )
            profile_operation_keys[profile] = {
                str(record.get("operation")) for record in profile_records if record.get("operation")
            }
            profile_depth[profile] = _profile_depth(profile_records, usable_keys)
            result["reachability"][profile] = {
                "attempted": len(profile_records),
                "reached": reached,
                "operations_exercised": sorted(profile_operation_keys[profile]),
            }
        exercised_operation_keys = set().union(*profile_operation_keys.values())
        result["reachability"]["operations_exercised"] = sorted(exercised_operation_keys)
        result["campaign"]["scheduling"]["coverage"] = {
            "usable_operations": usable_count,
            "operations_exercised": len(exercised_operation_keys & usable_keys),
            "coverage_percent": round(100 * len(exercised_operation_keys & usable_keys) / usable_count, 1),
            "per_profile": {
                profile: {
                    "operations_exercised": len(profile_operation_keys[profile] & usable_keys),
                    "coverage_percent": round(
                        100 * len(profile_operation_keys[profile] & usable_keys) / usable_count,
                        1,
                    ),
                }
                for profile in profiles
            },
        }
        result["campaign"]["scheduling"]["depth"] = {
            "per_profile": profile_depth,
        }

        outcomes = {outcome for values in profile_outcomes.values() for outcome in values}
        confirmation_incomplete = any(
            anomaly.get("type") in {"confirmation_incomplete", "confirmation_infrastructure_error"}
            for anomaly in anomalies
        )
        if outcomes == {"tool_error"} or not interactions:
            result["campaign"]["state"] = "unavailable"
            result["campaign"]["stop_reason"] = "schemathesis_did_not_execute_requests"
        elif confirmation_incomplete:
            result["campaign"]["state"] = "partial"
            if any(anomaly.get("type") == "confirmation_infrastructure_error" for anomaly in anomalies):
                result["campaign"]["stop_reason"] = "confirmation_infrastructure_error"
            else:
                result["campaign"]["stop_reason"] = "confirmation_incomplete"
        elif "tool_error" in outcomes:
            result["campaign"]["state"] = "partial"
            result["campaign"]["stop_reason"] = "schemathesis_stage_error"
        elif any(not usable_keys.issubset(profile_operation_keys[profile]) for profile in profiles):
            result["campaign"]["state"] = "partial"
            result["campaign"]["stop_reason"] = "coverage_incomplete"
        else:
            result["campaign"]["state"] = "completed"
            result["campaign"]["stop_reason"] = "all_profiles_breadth_complete"
    except Exception as error:  # The unavailable result must survive setup/tool failures.
        result["campaign"]["state"] = "unavailable"
        result["campaign"]["stop_reason"] = "runner_error"
        result["errors"].append({"type": type(error).__name__, "message": str(error)})
    finally:
        result["campaign"]["requests_executed"] = _read_counter(artifacts / "request-count.txt")
        result["campaign"]["finished_at"] = utc_now()
        validation_errors = validate_result(result)
        if validation_errors:
            result["campaign"]["state"] = "unavailable"
            result["errors"].append({"type": "result_contract_error", "messages": validation_errors})
        _write_json(output, result)

    return result


def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline-routes", type=Path, required=True)
    parser.add_argument("--sut-routes", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--artifacts", type=Path, required=True)
    parser.add_argument("--site-url", required=True)
    parser.add_argument("--qit", required=True)
    parser.add_argument("--env-id", required=True)
    parser.add_argument("--sut", type=json.loads, required=True)
    parser.add_argument("--environment", type=json.loads, required=True)
    parser.add_argument("--suppressions", type=Path)
    args = parser.parse_args(argv)
    result = run_campaign(
        args.baseline_routes,
        args.sut_routes,
        args.output,
        args.artifacts,
        args.site_url,
        args.qit,
        args.env_id,
        args.sut,
        args.environment,
        args.suppressions,
    )
    return 0 if result["campaign"]["state"] != "unavailable" else 1


if __name__ == "__main__":
    raise SystemExit(main())
