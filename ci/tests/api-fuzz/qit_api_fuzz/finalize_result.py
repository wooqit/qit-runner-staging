"""Ensure every API-fuzz workflow sends a valid terminal result to Manager."""

from __future__ import annotations

import json
import os
import re
from pathlib import Path
from typing import Any, Dict, Mapping, Optional

from .result import empty_result, utc_now, validate_result


def _read_failure(path: Path) -> Optional[Dict[str, str]]:
    if not path.is_file():
        return None

    try:
        decoded = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None

    if not isinstance(decoded, Mapping):
        return None

    stage = str(decoded.get("stage", ""))
    code = str(decoded.get("code", ""))
    message = re.sub(r"[\x00-\x1f\x7f]+", " ", str(decoded.get("message", ""))).strip()
    if stage not in {"plugin_download", "setup", "analysis"}:
        return None
    if not re.fullmatch(r"[a-z0-9_]+", code) or not message:
        return None

    return {"stage": stage, "code": code, "message": message[:500]}


def _has_valid_result(path: Path) -> bool:
    if not path.is_file():
        return False

    try:
        decoded = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return False

    return isinstance(decoded, Mapping) and not validate_result(decoded)


def finalize_result(
    result_path: Path,
    failure_path: Path,
    sut: Mapping[str, Any],
    environment: Mapping[str, Any],
    *,
    cancelled: bool = False,
) -> bool:
    """Write a cancellation marker or an unavailable result when no campaign result exists."""

    if cancelled:
        result_path.parent.mkdir(parents=True, exist_ok=True)
        result_path.write_text("{}\n", encoding="utf-8")
        return True

    if _has_valid_result(result_path):
        return False

    failure = _read_failure(failure_path)
    reason = failure["message"] if failure else "API fuzz workflow did not produce a campaign result."
    error_type = failure["code"] if failure else "workflow_setup_failed"
    timestamp = utc_now()

    result = empty_result(sut, environment)
    result["campaign"].update(
        {
            "state": "unavailable",
            "started_at": timestamp,
            "finished_at": timestamp,
            "stop_reason": "environment_setup_failed",
        }
    )
    result["errors"].append({"type": error_type, "message": reason})
    if failure:
        result["failure"] = failure

    result_path.parent.mkdir(parents=True, exist_ok=True)
    result_path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return True


def main() -> int:
    sut_slug = os.environ.get("SUT_SLUG", "")
    if sut_slug.startswith("wporg-"):
        sut_slug = sut_slug.removeprefix("wporg-")

    woo_id = os.environ.get("SUT_WOO_ID", "0")
    sut = {
        "woo_id": int(woo_id) if woo_id.isdigit() else 0,
        "slug": sut_slug,
        "version": os.environ.get("SUT_VERSION", ""),
    }
    environment = {
        "wordpress": os.environ.get("WORDPRESS_VERSION", ""),
        "woocommerce": os.environ.get("WOOCOMMERCE_VERSION", ""),
        "php": os.environ.get("PHP_VERSION", ""),
    }
    cancelled = os.environ.get("CANCELLED", "").lower() in {"1", "true", "yes", "on"}

    finalize_result(
        Path(os.environ["RESULT_PATH"]),
        Path(os.environ.get("FAILURE_PATH", "")),
        sut,
        environment,
        cancelled=cancelled,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
