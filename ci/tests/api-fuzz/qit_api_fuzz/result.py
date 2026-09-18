"""Versioned API-fuzz result contract and exact-fingerprint suppressions."""

from __future__ import annotations

import hashlib
import json
from datetime import date, datetime, timezone
from typing import Any, Dict, Iterable, List, Mapping, MutableMapping, Optional, Sequence


RESULT_SCHEMA_VERSION = "1.0.0"
TEST_TYPE = "api-fuzz"
CAMPAIGN_STATES = {"completed", "partial", "not_applicable", "unavailable"}
REQUIRED_SECTIONS = {
    "sut": dict,
    "environment": dict,
    "campaign": dict,
    "discovery": dict,
    "schema": dict,
    "reachability": dict,
    "findings": list,
    "suppressions": list,
    "anomalies": list,
    "errors": list,
    "artifacts": dict,
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def empty_result(sut: Mapping[str, Any], environment: Mapping[str, Any]) -> Dict[str, Any]:
    """Create a complete, serializable result skeleton for a runner."""

    return {
        "schema_version": RESULT_SCHEMA_VERSION,
        "test_type": TEST_TYPE,
        "sut": dict(sut),
        "environment": dict(environment),
        "campaign": {
            "state": "unavailable",
            "started_at": None,
            "finished_at": None,
            "request_budget": 2500,
            "time_budget_seconds": 1200,
            "requests_executed": 0,
            "stop_reason": None,
            "overhead": {
                "generation_seconds": 0.0,
                "confirmation_seconds": 0.0,
                "reset_count": 0,
                "reset_seconds_total": 0.0,
                "reset_seconds_median": 0.0,
                "reset_seconds_p95": 0.0,
                "reset_strategy": "unknown",
                "reset_strategies": {},
                "reset_limiting_phase": None,
                "reset_phases": {},
            },
            "isolation": {
                "database": "restored_before_each_generated_operation_batch_and_before_each_replay",
                "object_cache": "flushed_with_database_restore",
                "external_http": "blocked_for_marked_requests",
                "email": "blocked_for_marked_requests",
                "filesystem": "not_restored",
                "generation_reset_strategy": "operation_batch",
            },
        },
        "discovery": {
            "scope": "sut_owned",
            "baseline_operation_count": 0,
            "sut_operation_count": 0,
            "target_operation_count": 0,
            "sut_owned_operation_count": 0,
            "shared_modified_operation_count": 0,
            "excluded_operation_count": 0,
            "activation_added_operation_count": 0,
            "activation_modified_operation_count": 0,
            "added": [],
            "modified": [],
            "sut_owned": [],
            "shared_modified": [],
            "unchanged_count": 0,
            "removed_count": 0,
        },
        "schema": {
            "openapi_version": "3.0.3",
            "complete": 0,
            "partial": 0,
            "untyped": 0,
            "unsupported": 0,
            "operations": [],
        },
        "reachability": {
            "anonymous": {"attempted": 0, "reached": 0},
            "administrator": {"attempted": 0, "reached": 0},
        },
        "findings": [],
        "suppressions": [],
        "anomalies": [],
        "errors": [],
        "artifacts": {"retention_days": 14, "private": True, "items": []},
    }


def validate_result(result: Mapping[str, Any]) -> List[str]:
    """Validate the stable cross-repository contract without third-party dependencies."""

    errors: List[str] = []
    if result.get("schema_version") != RESULT_SCHEMA_VERSION:
        errors.append(f"schema_version must be {RESULT_SCHEMA_VERSION}")
    if result.get("test_type") != TEST_TYPE:
        errors.append(f"test_type must be {TEST_TYPE}")

    for name, expected_type in REQUIRED_SECTIONS.items():
        if not isinstance(result.get(name), expected_type):
            errors.append(f"{name} must be a {expected_type.__name__}")

    campaign = result.get("campaign")
    if isinstance(campaign, Mapping):
        if campaign.get("state") not in CAMPAIGN_STATES:
            errors.append("campaign.state is invalid")
        for name in ("request_budget", "time_budget_seconds", "requests_executed"):
            if not isinstance(campaign.get(name), int) or campaign[name] < 0:
                errors.append(f"campaign.{name} must be a non-negative integer")

    discovery = result.get("discovery")
    if isinstance(discovery, Mapping):
        if discovery.get("scope") != "sut_owned":
            errors.append("discovery.scope must be sut_owned")
        for name in (
            "baseline_operation_count",
            "sut_operation_count",
            "target_operation_count",
            "sut_owned_operation_count",
            "shared_modified_operation_count",
            "excluded_operation_count",
        ):
            if not isinstance(discovery.get(name), int) or discovery[name] < 0:
                errors.append(f"discovery.{name} must be a non-negative integer")

    findings = result.get("findings")
    if isinstance(findings, list):
        for index, finding in enumerate(findings):
            if not isinstance(finding, Mapping):
                errors.append(f"findings[{index}] must be an object")
                continue
            for field in ("fingerprint", "finding_type", "method", "route", "confirmation"):
                if field not in finding:
                    errors.append(f"findings[{index}].{field} is required")
            confirmation = finding.get("confirmation")
            if (
                not isinstance(confirmation, Mapping)
                or confirmation.get("clean_state_replays") != 2
                or confirmation.get("reproduced") != 2
            ):
                errors.append(f"findings[{index}].confirmation must record two clean-state replays")

    artifacts = result.get("artifacts")
    if isinstance(artifacts, Mapping):
        if artifacts.get("private") is not True:
            errors.append("artifacts.private must be true")
        if artifacts.get("retention_days") != 14:
            errors.append("artifacts.retention_days must be 14")

    return errors


def finding_fingerprint(finding: Mapping[str, Any]) -> str:
    """Create a stable key suitable for narrow, auditable false-positive suppression."""

    material = {
        "finding_type": finding.get("finding_type", ""),
        "method": str(finding.get("method", "")).upper(),
        "route": finding.get("route", ""),
        "error_type": finding.get("error_type", ""),
        "error_file": finding.get("error_file", ""),
        "error_line": int(finding.get("error_line", 0) or 0),
    }
    canonical = json.dumps(material, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def apply_suppressions(
    findings: Sequence[MutableMapping[str, Any]],
    suppressions: Iterable[Mapping[str, Any]],
    today: Optional[date] = None,
) -> List[Dict[str, Any]]:
    """Apply only exact, unexpired fingerprints and retain an audit record on each match."""

    today = today or date.today()
    active: Dict[str, Mapping[str, Any]] = {}
    for suppression in suppressions:
        fingerprint = suppression.get("fingerprint")
        if not isinstance(fingerprint, str) or not fingerprint:
            continue
        expires_at = suppression.get("expires_at")
        if expires_at:
            try:
                if date.fromisoformat(str(expires_at)) < today:
                    continue
            except ValueError:
                continue
        active[fingerprint] = suppression

    applied: List[Dict[str, Any]] = []
    for finding in findings:
        fingerprint = str(finding.get("fingerprint") or finding_fingerprint(finding))
        finding["fingerprint"] = fingerprint
        suppression = active.get(fingerprint)
        if suppression is None:
            finding["suppressed"] = False
            continue
        finding["suppressed"] = True
        finding["suppression"] = {
            "reason": suppression.get("reason", ""),
            "owner": suppression.get("owner", ""),
            "expires_at": suppression.get("expires_at"),
        }
        applied.append(
            {
                "fingerprint": fingerprint,
                "reason": suppression.get("reason", ""),
                "owner": suppression.get("owner", ""),
                "expires_at": suppression.get("expires_at"),
            }
        )
    return applied
