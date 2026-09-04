#!/usr/bin/env bash

set -euo pipefail

ACTIVATION_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPOSITORY_ROOT="$(cd "${ACTIVATION_ROOT}/../../.." && pwd)"
PACKAGE_ROOT="${ACTIVATION_ROOT}/test-package"
LEGACY_ROOT="${REPOSITORY_ROOT}/ci/tests/activation-legacy/custom-test"

php -l "${PACKAGE_ROOT}/bootstrap/mu-plugin.php"
php -l "${LEGACY_ROOT}/bootstrap/mu-plugin.php"
php -l "${ACTIVATION_ROOT}/fixtures/qit-activation-smoke-safe/qit-activation-smoke-safe.php"
php -l "${ACTIVATION_ROOT}/fixtures/qit-activation-smoke-broken/qit-activation-smoke-broken.php"

node --check "${PACKAGE_ROOT}/tests/hook-resilience.js"
node --check "${PACKAGE_ROOT}/tests/activation.spec.js"
node --check "${LEGACY_ROOT}/hook-resilience.js"
node --check "${LEGACY_ROOT}/activation.spec.js"
node --test "${ACTIVATION_ROOT}/validation/hook-resilience.test.mjs"
node "${ACTIVATION_ROOT}/validation/validate-activation-spec-parity.mjs"

cmp "${PACKAGE_ROOT}/tests/hook-resilience.js" "${LEGACY_ROOT}/hook-resilience.js"
cmp "${PACKAGE_ROOT}/bootstrap/mu-plugin.php" "${LEGACY_ROOT}/bootstrap/mu-plugin.php"
