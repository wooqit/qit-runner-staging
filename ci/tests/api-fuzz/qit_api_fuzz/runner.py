"""Provision a QIT environment and run the internal API-fuzz campaign."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Mapping, Optional, Sequence

from .campaign import run_campaign
from .result import empty_result, utc_now


REQUIRED_ENV = (
    "PLUGINS_JSON",
    "SUT_WOO_ID",
    "SUT_SLUG",
    "WORDPRESS_VERSION",
    "WOOCOMMERCE_VERSION",
    "PHP_VERSION",
)
KNOWN_ANSWER_SUT_SLUG = "qit-api-fuzz-synthetic-sut"


def _plugins(raw: str) -> List[Dict[str, Any]]:
    value = raw.strip()
    if value.startswith("'") and value.endswith("'"):
        value = value[1:-1]
    decoded = json.loads(value)
    if not isinstance(decoded, list):
        raise ValueError("PLUGINS_JSON must be an array")
    return [plugin for plugin in decoded if isinstance(plugin, dict)]


def _plugin_slug(value: Any) -> str:
    slug = str(value or "")
    return "woocommerce" if slug == "wporg-woocommerce" else slug


def _local_plugin_source(
    root: Path, test_root: Path, slug: str, source_directory: str
) -> Path:
    """Resolve a downloaded extension or the repository-owned live known-answer fixture."""

    if slug == KNOWN_ANSWER_SUT_SLUG:
        return test_root / "test-package" / "fixtures" / KNOWN_ANSWER_SUT_SLUG
    return root / "ci" / "plugins" / source_directory


def _qit_provenance(qit: Path, metadata_path: Path) -> Dict[str, Any]:
    """Identify the exact host-side QIT CLI artifact used by the campaign."""

    metadata: Dict[str, Any] = {}
    if metadata_path.exists():
        decoded = json.loads(metadata_path.read_text(encoding="utf-8"))
        if not isinstance(decoded, dict):
            raise ValueError(f"{metadata_path} must contain a JSON object")
        metadata.update(decoded)
    actual_checksum = hashlib.sha256(qit.read_bytes()).hexdigest()
    declared_checksum = metadata.get("sha256")
    if declared_checksum is not None and declared_checksum != actual_checksum:
        raise ValueError("Embedded QIT CLI checksum does not match qit-source.json")
    metadata["sha256"] = actual_checksum
    return metadata


def _environment_json(output: str) -> Dict[str, Any]:
    try:
        value = json.loads(output)
        if isinstance(value, dict) and value.get("env_id"):
            return value
    except json.JSONDecodeError:
        pass

    for line in reversed(output.splitlines()):
        try:
            value = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict) and value.get("env_id"):
            return value
    raise RuntimeError("QIT env:up did not return environment JSON")


def _write_unavailable(output: Path, sut: Mapping[str, Any], environment: Mapping[str, Any], error: Exception) -> None:
    result = empty_result(sut, environment)
    result["campaign"].update(
        {
            "state": "unavailable",
            "started_at": utc_now(),
            "finished_at": utc_now(),
            "stop_reason": "environment_setup_failed",
        }
    )
    result["errors"].append({"type": type(error).__name__, "message": str(error)})
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"API fuzz runner unavailable: {type(error).__name__}: {error}", file=sys.stderr)
    print(f"Structured failure result: {output}", file=sys.stderr)


def main(argv: Optional[Sequence[str]] = None) -> int:
    missing = [name for name in REQUIRED_ENV if not os.environ.get(name)]
    if missing:
        raise RuntimeError("Missing required environment variables: " + ", ".join(missing))

    root = Path(__file__).resolve().parents[4]
    test_root = Path(__file__).resolve().parent.parent
    package = test_root / "test-package"
    artifacts = package / "artifacts"
    output = root / "ci" / "results" / "api-fuzz-results.json"
    qit = (test_root / "qit").resolve()
    sut_slug = _plugin_slug(os.environ["SUT_SLUG"])

    sut = {
        "woo_id": int(os.environ["SUT_WOO_ID"]),
        "slug": sut_slug,
        "version": os.environ.get("SUT_VERSION", ""),
    }
    environment = {
        "wordpress": os.environ["WORDPRESS_VERSION"],
        "woocommerce": os.environ["WOOCOMMERCE_VERSION"],
        "php": os.environ["PHP_VERSION"],
    }
    env_id = ""

    try:
        environment["qit_cli"] = _qit_provenance(qit, test_root / "qit-source.json")
        plugins = _plugins(os.environ["PLUGINS_JSON"])
        slugs = list(dict.fromkeys(_plugin_slug(plugin.get("slug")) for plugin in plugins if plugin.get("slug")))
        if sut_slug not in slugs:
            slugs.append(sut_slug)

        sut_source_directory = os.environ.get("SUT_SOURCE_DIRECTORY", sut_slug)

        command = [
            str(qit),
            "env:up",
            "--json",
            "--no-interaction",
            "--skip_activating_plugins",
            "--wordpress_version",
            os.environ["WORDPRESS_VERSION"],
            "--woocommerce_version",
            os.environ["WOOCOMMERCE_VERSION"],
            "--php_version",
            os.environ["PHP_VERSION"],
            "--timeout",
            "30",
            "--test-package",
            str(package),
            "--env",
            f"QIT_SUT_SLUG={sut_slug}",
            "--env",
            "QIT_API_FUZZ_STACK=" + ",".join(slugs),
        ]
        for slug in slugs:
            source_directory = sut_source_directory if slug == sut_slug else slug
            local_source = _local_plugin_source(root, test_root, slug, source_directory)
            source = f"{slug}@{local_source}" if local_source.is_dir() else slug
            command.extend(["--plugin", source])

        completed = subprocess.run(command, capture_output=True, text=True, timeout=600, check=False)
        if completed.returncode != 0:
            raise RuntimeError((completed.stderr or completed.stdout)[-4000:])
        env_info = _environment_json(completed.stdout)
        env_id = str(env_info["env_id"])
        site_url = str(env_info.get("site_url") or "")
        if not site_url:
            raise RuntimeError("QIT environment did not expose site_url")

        result = run_campaign(
            artifacts / "baseline-routes.json",
            artifacts / "sut-routes.json",
            output,
            artifacts,
            site_url,
            str(qit),
            env_id,
            sut,
            environment,
            test_root / "suppressions.json",
        )
        return 0 if result["campaign"]["state"] != "unavailable" else 1
    except Exception as error:
        _write_unavailable(output, sut, environment, error)
        return 1
    finally:
        if env_id:
            subprocess.run([str(qit), "env:down", env_id, "--no-interaction"], timeout=180, check=False)


if __name__ == "__main__":
    raise SystemExit(main())
