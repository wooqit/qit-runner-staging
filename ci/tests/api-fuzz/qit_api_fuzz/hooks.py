"""Schemathesis lifecycle hooks for operation-batch isolation and evidence capture."""

from __future__ import annotations

import fcntl
import json
import os
import uuid
from pathlib import Path
from typing import Any, Dict, Mapping

import requests
import schemathesis

from .request_body import capture_request_body
from .reset import run_reset


SENSITIVE_HEADERS = {"authorization", "cookie", "proxy-authorization", "set-cookie"}

# Timing of the reset that preceded the request currently in flight, handed from before_call to
# after_call. Schemathesis runs a single worker, so one request is in flight at a time.
_LAST_RESET: Dict[str, Any] = {"performed": False, "seconds": 0.0, "observation": None}
_LAST_OPERATION: Dict[str, Any] = {"key": None}


def _reset_every() -> int:
    try:
        return max(1, int(os.environ.get("QIT_API_FUZZ_RESET_EVERY", "1")))
    except ValueError:
        return 1


def _reset_mode() -> str:
    configured = os.environ.get("QIT_API_FUZZ_RESET_MODE")
    if configured in {"operation", "request"}:
        return configured
    # Preserve the original tuning escape hatch for direct hook users. Campaign runs explicitly
    # select operation batching, so a stale RESET_EVERY environment variable cannot silently alter
    # the result contract.
    return "request" if "QIT_API_FUZZ_RESET_EVERY" in os.environ else "operation"


def _append(record: Mapping[str, Any]) -> None:
    path = Path(os.environ["QIT_API_FUZZ_LEDGER"])
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as stream:
        fcntl.flock(stream.fileno(), fcntl.LOCK_EX)
        stream.write(json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n")
        stream.flush()
        fcntl.flock(stream.fileno(), fcntl.LOCK_UN)


def _read_count() -> int:
    path = Path(os.environ["QIT_API_FUZZ_COUNTER"])
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a+", encoding="utf-8") as stream:
        fcntl.flock(stream.fileno(), fcntl.LOCK_EX)
        stream.seek(0)
        value = stream.read().strip()
        count = int(value) if value else 0
        fcntl.flock(stream.fileno(), fcntl.LOCK_UN)
    return count


def _increment_count() -> int:
    path = Path(os.environ["QIT_API_FUZZ_COUNTER"])
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a+", encoding="utf-8") as stream:
        fcntl.flock(stream.fileno(), fcntl.LOCK_EX)
        stream.seek(0)
        value = stream.read().strip()
        count = (int(value) if value else 0) + 1
        stream.seek(0)
        stream.truncate()
        stream.write(str(count))
        stream.flush()
        fcntl.flock(stream.fileno(), fcntl.LOCK_UN)
    return count


def _reset_environment() -> Dict[str, Any]:
    return run_reset(
        os.environ["QIT_API_FUZZ_QIT"],
        os.environ["QIT_API_FUZZ_ENV_ID"],
    )


def _events(request_id: str) -> list[dict[str, Any]]:
    url = (
        os.environ["QIT_API_FUZZ_SITE_URL"].rstrip("/")
        + "/wp-json/qit-api-fuzz/v1/events/"
        + request_id
    )
    try:
        response = requests.get(
            url,
            auth=(
                os.environ.get("QIT_API_FUZZ_ADMIN_USER", "admin"),
                os.environ.get("QIT_API_FUZZ_ADMIN_PASSWORD", "password"),
            ),
            headers={
                "X-QIT-API-Fuzz": "1",
                "X-QIT-API-Fuzz-Request-ID": request_id,
            },
            timeout=10,
        )
        data = response.json() if response.ok else []
        return data if isinstance(data, list) else []
    except (requests.RequestException, ValueError):
        return []


def _operation_route(case: Any) -> str:
    try:
        raw = case.operation.definition.raw
        if isinstance(raw, Mapping) and isinstance(raw.get("x-qit-route-regex"), str):
            return raw["x-qit-route-regex"]
    except (AttributeError, TypeError):
        pass
    try:
        return str(case.operation.path)
    except AttributeError:
        return ""


def _operation_key(case: Any) -> str:
    try:
        raw = case.operation.definition.raw
        if isinstance(raw, Mapping) and isinstance(raw.get("x-qit-operation-key"), str):
            return raw["x-qit-operation-key"]
    except (AttributeError, TypeError):
        pass
    try:
        return f"{case.operation.method.upper()} {case.operation.path}"
    except AttributeError:
        return ""


def _response_error_code(response: Any) -> str:
    try:
        value = response.json()
    except (AttributeError, requests.RequestException, ValueError):
        return ""
    return str(value.get("code", "")) if isinstance(value, Mapping) else ""


@schemathesis.hook
def before_call(ctx: Any, case: Any, kwargs: Dict[str, Any]) -> None:
    budget = int(os.environ.get("QIT_API_FUZZ_REQUEST_BUDGET", "2500"))
    count = _read_count()
    if count >= budget:
        _append({"type": "budget_exhausted", "request_budget": budget})
        raise KeyboardInterrupt

    # Generation restores one clean snapshot when Schemathesis moves to a new operation. Multiple
    # examples for that operation then share the batch, which makes breadth practical while retaining
    # an explicit isolation boundary. Confirmation always restores before each replay, so state-only
    # faults do not become findings. Request cadence remains available for isolated hook experiments.
    _LAST_RESET["performed"] = False
    _LAST_RESET["seconds"] = 0.0
    _LAST_RESET["observation"] = None
    operation_key = _operation_key(case)
    should_reset = (
        count % _reset_every() == 0
        if _reset_mode() == "request"
        else _LAST_OPERATION["key"] != operation_key
    )
    if should_reset:
        observation = _reset_environment()
        if not isinstance(observation, Mapping):  # Keep direct hook tests lightweight.
            observation = {
                "status": "completed",
                "reason": None,
                "reset_performed": True,
                "seconds": 0.0,
                "cli_seconds": 0.0,
                "caller_overhead_seconds": 0.0,
                "strategy": "unknown",
                "failed_phase": None,
                "message": "",
                "phases": {},
            }
        _LAST_RESET["performed"] = True
        _LAST_RESET["seconds"] = float(observation.get("seconds", 0.0))
        _LAST_RESET["observation"] = dict(observation)
        if os.environ.get("QIT_API_FUZZ_LEDGER"):
            _append(
                {
                    "type": "reset",
                    "scope": "generation",
                    "profile": os.environ.get("QIT_API_FUZZ_PROFILE", "unknown"),
                    "operation": operation_key,
                    **dict(observation),
                }
            )
        if observation.get("status") != "completed":
            if os.environ.get("QIT_API_FUZZ_LEDGER"):
                _append(
                    {
                        "type": "isolation_error",
                        "profile": os.environ.get("QIT_API_FUZZ_PROFILE", "unknown"),
                        "operation": operation_key,
                        "reason": observation.get("reason", "clean_state_restore_failed"),
                        "failed_phase": observation.get("failed_phase"),
                        "message": str(observation.get("message", ""))[-2000:],
                    }
                )
            raise RuntimeError("QIT failed to restore the clean database snapshot")
    _LAST_OPERATION["key"] = operation_key
    request_number = _increment_count()

    if case.headers is None:
        case.headers = {}
    request_id = str(uuid.uuid4())
    case.headers["X-QIT-API-Fuzz"] = "1"
    case.headers["X-QIT-API-Fuzz-Request-ID"] = request_id
    case.headers["X-QIT-API-Fuzz-Request-Number"] = str(request_number)


@schemathesis.hook
def after_call(ctx: Any, case: Any, response: Any) -> None:
    prepared = response.request
    headers = {
        str(name): str(value)
        for name, value in dict(prepared.headers or {}).items()
        if str(name).lower() not in SENSITIVE_HEADERS
    }
    request_id = headers.get("X-QIT-API-Fuzz-Request-ID", "")
    operation_key = _operation_key(case)

    record: Dict[str, Any] = {
        "type": "interaction",
        "profile": os.environ.get("QIT_API_FUZZ_PROFILE", "unknown"),
        "request_id": request_id,
        "operation": operation_key,
        "route": _operation_route(case),
        "method": str(prepared.method or ""),
        "url": str(prepared.url or ""),
        "headers": headers,
        "response_status": int(response.status_code),
        "response_error_code": _response_error_code(response),
        "response_elapsed": float(response.elapsed),
        "reset_performed": bool(_LAST_RESET["performed"]),
        "reset_seconds": float(_LAST_RESET["seconds"]),
        "reset_strategy": (
            str(_LAST_RESET["observation"].get("strategy", "unknown"))
            if isinstance(_LAST_RESET.get("observation"), Mapping)
            else "unknown"
        ),
        "reset_phases": (
            dict(_LAST_RESET["observation"].get("phases", {}))
            if isinstance(_LAST_RESET.get("observation"), Mapping)
            else {}
        ),
        "instrumentation_events": _events(request_id) if request_id else [],
    }
    artifact_root = Path(os.environ["QIT_API_FUZZ_LEDGER"]).parent
    record.update(capture_request_body(prepared.body, artifact_root))
    _append(record)
