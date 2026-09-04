#!/usr/bin/env bash

set -euo pipefail

ACTIVATION_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPOSITORY_ROOT="$(cd "${ACTIVATION_ROOT}/../../.." && pwd)"
QIT_BIN="${QIT_BIN:-${ACTIVATION_ROOT}/qit}"
QIT_DATA_DIR="${QIT_HOME:-${REPOSITORY_ROOT}/qit-home}"
PHP_BIN="${PHP_BIN:-php}"
KNOWN_SUT="${QIT_KNOWN_ANSWER_SUT:-google-listings-and-ads}"
PACKAGE_ROOT="${ACTIVATION_ROOT}/test-package"
RESULTS_DIR="$(mktemp -d)"

trap 'rm -rf "${RESULTS_DIR}"' EXIT

run_fixture() {
	local fixture_path="$1"
	local log_path="$2"

	set +e
	QIT_HOME="${QIT_DATA_DIR}" "${QIT_BIN}" run:e2e "${KNOWN_SUT}" \
		--zip="${fixture_path}" \
		--test-package="${PACKAGE_ROOT}" \
		--skip_activating_plugins \
		-- --grep="@release-smoke" >"${log_path}" 2>&1
	local exit_code=$?
	set -e

	return "${exit_code}"
}

SAFE_LOG="${RESULTS_DIR}/safe.log"
BROKEN_LOG="${RESULTS_DIR}/broken.log"

if ! run_fixture \
	"${ACTIVATION_ROOT}/fixtures/qit-activation-smoke-safe" \
	"${SAFE_LOG}"; then
	cat "${SAFE_LOG}"
	echo "Safe activation hook-resilience fixture unexpectedly failed." >&2
	exit 1
fi
echo "Safe activation hook-resilience fixture passed."

if run_fixture \
	"${ACTIVATION_ROOT}/fixtures/qit-activation-smoke-broken" \
	"${BROKEN_LOG}"; then
	cat "${BROKEN_LOG}"
	echo "Broken activation hook-resilience fixture unexpectedly passed." >&2
	exit 1
fi

for expected_evidence in \
	"QIT_ACTIVATION_SMOKE_REGRESSION" \
	"TypeError" \
	"post_activation normal /wp-admin/admin.php?page=wc-settings status=500 reasons=http_500,php_fatal sut-attributed" \
	"post_activation normal /wp-json/qit-activation-smoke/v1/probes/wp-mail status=500 reasons=http_500,php_fatal sut-attributed" \
	"post_activation pre_http_request:wp_error /wp-json/qit-activation-smoke/v1/probes/pre-http-request status=500 reasons=http_500,php_fatal sut-attributed" \
	"post_activation rest_authentication_errors:wp_error /wp-json/ status=500 reasons=http_500,php_fatal sut-attributed" \
	"post_activation rest_pre_serve_request:null /wp-json/ status=500 reasons=http_500,php_fatal sut-attributed" \
	"post_activation rest_pre_serve_request:null /wp-json/wp/v2/taxonomies/product_cat?context=edit&_locale=user status=500 reasons=http_500,php_fatal sut-attributed"; do
	if ! grep -Fq -- "${expected_evidence}" "${BROKEN_LOG}"; then
		cat "${BROKEN_LOG}"
		echo "Broken fixture output did not contain classified failure: ${expected_evidence}" >&2
		exit 1
	fi
done

BROKEN_RUN_ID="$(
	grep -oE 'qit_results=[0-9]+' "${BROKEN_LOG}" \
		| tail -n 1 \
		| cut -d= -f2
)" || true
if [[ -z "${BROKEN_RUN_ID}" ]]; then
	cat "${BROKEN_LOG}"
	echo "Could not identify the uploaded broken-fixture run." >&2
	exit 1
fi

BROKEN_RESULT="${RESULTS_DIR}/broken-result.json"
set +e
# Silence deprecation notices from the qit PHAR so they cannot pollute the
# --json output on stdout.
QIT_HOME="${QIT_DATA_DIR}" "${PHP_BIN}" \
	-d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
	"${QIT_BIN}" get "${BROKEN_RUN_ID}" --json >"${BROKEN_RESULT}" 2>>"${BROKEN_LOG}"
set -e

for expected_debug_evidence in \
	'"debug_log":' \
	'PHP Fatal error:' \
	'qit_activation_smoke_broken_settings_callback' \
	'qit_activation_smoke_broken_mail_from_callback' \
	'qit_activation_smoke_broken_http_callback' \
	'qit_activation_smoke_broken_authentication_callback' \
	'qit_activation_smoke_broken_callback'; do
	if ! grep -Fq -- "${expected_debug_evidence}" "${BROKEN_RESULT}"; then
		cat "${BROKEN_LOG}"
		echo "Uploaded broken-fixture result did not retain PHP debug evidence: ${expected_debug_evidence}" >&2
		exit 1
	fi
done

echo "Activation hook-resilience known-answer checks passed."
