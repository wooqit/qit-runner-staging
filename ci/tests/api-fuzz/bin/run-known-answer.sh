#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
python_bin="${PYTHON:-python3}"

export PLUGINS_JSON='[]'
export SUT_WOO_ID=0
export SUT_SLUG=qit-api-fuzz-synthetic-sut
export SUT_VERSION=1.0.0
export WORDPRESS_VERSION="${QIT_API_FUZZ_WORDPRESS_VERSION:-stable}"
export WOOCOMMERCE_VERSION="${QIT_API_FUZZ_WOOCOMMERCE_VERSION:-stable}"
export PHP_VERSION="${QIT_API_FUZZ_PHP_VERSION:-8.3}"
export QIT_HOME="${QIT_HOME:-$repo_root/qit-home}"
export PHPRC="${PHPRC:-$repo_root/ci/tests/api-fuzz/php.ini}"

cd "$repo_root"
PYTHONPATH=ci/tests/api-fuzz "$python_bin" -m qit_api_fuzz.runner

result="$repo_root/ci/results/api-fuzz-results.json"
"$python_bin" - "$result" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path, encoding="utf-8") as stream:
    result = json.load(stream)

expected = {
    "/qit-fuzz-fixture/v1/deterministic-fatal",
    "/qit-fuzz-fixture/v1/swallowed-fatal",
}
findings = result.get("findings", [])
actual = {finding.get("route") for finding in findings}
clean_was_exercised = "GET /qit-fuzz-fixture/v1/clean" in result.get("reachability", {}).get(
    "operations_exercised", []
)

if (
    result.get("campaign", {}).get("state") != "completed"
    or actual != expected
    or not all(finding.get("is_sut_attributed") for finding in findings)
    or not clean_was_exercised
):
    raise SystemExit(
        "Known-answer campaign failed: expected a completed run, two attributed planted faults, "
        "and an exercised clean control route."
    )

print("Known-answer campaign passed: both planted faults reproduced and the clean route stayed clean.")
PY
