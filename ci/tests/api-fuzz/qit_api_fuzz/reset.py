"""Structured qit env:reset execution shared by generation and confirmation."""

from __future__ import annotations

import json
import subprocess
import time
from typing import Any, Dict, Mapping, Optional


RESET_PHASES = (
    "environment_lookup",
    "snapshot_copy",
    "database_import",
    "temporary_file_cleanup",
    "object_cache_flush",
)
PHASE_STATUSES = {"completed", "skipped", "failed", "not_started"}
PHASE_FAILURE_REASONS = {
    "environment_lookup": "clean_state_environment_lookup_failed",
    "snapshot_copy": "clean_state_snapshot_copy_failed",
    "database_import": "clean_state_database_import_failed",
    "temporary_file_cleanup": "clean_state_temporary_file_cleanup_failed",
    "object_cache_flush": "clean_state_object_cache_flush_failed",
}


def _protocol_error(
    seconds: float, message: str, *, reset_performed: bool = True
) -> Dict[str, Any]:
    return {
        "status": "infrastructure_error",
        "reason": "clean_state_restore_protocol_error",
        "reset_performed": reset_performed,
        "seconds": round(seconds, 6),
        "cli_seconds": 0.0,
        "caller_overhead_seconds": 0.0,
        "strategy": "unknown",
        "failed_phase": None,
        "message": message[-2000:],
        "phases": {},
    }


def _parse_payload(payload: Mapping[str, Any], outer_seconds: float) -> Optional[Dict[str, Any]]:
    phases = payload.get("phases")
    if not isinstance(phases, Mapping) or any(name not in phases for name in RESET_PHASES):
        return None

    normalized_phases: Dict[str, Dict[str, Any]] = {}
    for name in RESET_PHASES:
        phase = phases.get(name)
        if not isinstance(phase, Mapping) or phase.get("status") not in PHASE_STATUSES:
            return None
        try:
            seconds = float(phase.get("seconds", 0.0))
        except (TypeError, ValueError):
            return None
        if seconds < 0:
            return None
        normalized_phases[name] = {
            "status": str(phase["status"]),
            "seconds": round(seconds, 6),
        }

    try:
        cli_seconds = float(payload.get("total_seconds", 0.0))
    except (TypeError, ValueError):
        return None
    if cli_seconds < 0:
        return None

    failed_phase = payload.get("failed_phase")
    if failed_phase is not None and failed_phase not in RESET_PHASES:
        return None
    status = str(payload.get("status", ""))
    if status not in {"success", "failed"}:
        return None
    if status == "success" and (
        failed_phase is not None
        or any(phase["status"] == "failed" for phase in normalized_phases.values())
    ):
        return None
    if status == "success" and (
        normalized_phases["environment_lookup"]["status"] != "completed"
        or normalized_phases["snapshot_copy"]["status"] not in {"completed", "skipped"}
        or normalized_phases["database_import"]["status"] != "completed"
        or normalized_phases["temporary_file_cleanup"]["status"] not in {"completed", "skipped"}
        or normalized_phases["object_cache_flush"]["status"] != "completed"
    ):
        return None
    if status == "failed" and (
        failed_phase is None or normalized_phases[failed_phase]["status"] != "failed"
    ):
        return None

    reason = None if status == "success" else PHASE_FAILURE_REASONS.get(
        str(failed_phase), "clean_state_restore_failed"
    )
    return {
        "status": "completed" if status == "success" else "infrastructure_error",
        "reason": reason,
        "reset_performed": True,
        "seconds": round(outer_seconds, 6),
        "cli_seconds": round(cli_seconds, 6),
        "caller_overhead_seconds": round(max(0.0, outer_seconds - cli_seconds), 6),
        "strategy": str(payload.get("strategy", "unknown")),
        "failed_phase": failed_phase,
        "message": str(payload.get("message", ""))[-2000:],
        "phases": normalized_phases,
    }


def run_reset(qit: str, env_id: str, timeout: int = 45) -> Dict[str, Any]:
    """Run env:reset and normalize its JSON protocol into one reset observation."""

    started = time.monotonic()
    try:
        completed = subprocess.run(
            [qit, "env:reset", env_id, "--json", "--no-interaction"],
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except subprocess.TimeoutExpired:
        seconds = time.monotonic() - started
        observation = _protocol_error(seconds, "QIT env:reset timed out")
        observation["reason"] = "clean_state_restore_timeout"
        return observation
    except OSError as error:
        seconds = time.monotonic() - started
        observation = _protocol_error(seconds, str(error), reset_performed=False)
        observation["reason"] = "clean_state_restore_exec_error"
        return observation

    outer_seconds = time.monotonic() - started
    payload = None
    for line in reversed(completed.stdout.splitlines()):
        try:
            candidate = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(candidate, Mapping):
            payload = candidate
            break
    if isinstance(payload, Mapping) and payload.get("env_id") != env_id:
        payload = None
    observation = _parse_payload(payload, outer_seconds) if isinstance(payload, Mapping) else None
    if observation is None:
        detail = completed.stderr.strip() or completed.stdout.strip() or "Missing reset JSON"
        return _protocol_error(outer_seconds, detail)
    if completed.returncode == 0 and observation["status"] != "completed":
        return _protocol_error(outer_seconds, "Failed reset returned a successful exit code")
    if completed.returncode != 0 and observation["status"] == "completed":
        return _protocol_error(outer_seconds, "Successful reset returned a failure exit code")
    return observation
