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
	local grep="${3:-@release-smoke}"

	set +e
	QIT_HOME="${QIT_DATA_DIR}" "${QIT_BIN}" run:e2e "${KNOWN_SUT}" \
		--zip="${fixture_path}" \
		--test-package="${PACKAGE_ROOT}" \
		--skip_activating_plugins \
		-- --grep="${grep}" >"${log_path}" 2>&1
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

# The footprint is report-only, so both fixtures must pass; only the report differs. The
# numbers are read from the annotation on the uploaded result, which is what the report shows.
expect_footprint() {
	local fixture_name="$1"
	shift
	local log_path="${RESULTS_DIR}/${fixture_name}.log"
	local result_path="${RESULTS_DIR}/${fixture_name}-result.json"
	local footprint_path="${RESULTS_DIR}/${fixture_name}-footprint.txt"

	if ! run_fixture "${ACTIVATION_ROOT}/fixtures/${fixture_name}" "${log_path}" "Activate Plugins|Record request footprint"; then
		cat "${log_path}"
		echo "Request footprint fixture ${fixture_name} unexpectedly failed the run." >&2
		exit 1
	fi

	local run_id
	run_id="$(grep -oE 'qit_results=[0-9]+' "${log_path}" | tail -n 1 | cut -d= -f2)" || true
	QIT_HOME="${QIT_DATA_DIR}" "${PHP_BIN}" \
		-d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
		"${QIT_BIN}" get "${run_id}" --json-results >"${result_path}" 2>>"${log_path}" || true
	if [[ -z "${run_id}" ]] || ! "${PHP_BIN}" "${ACTIVATION_ROOT}/bin/print-request-footprint.php" "${result_path}" >"${footprint_path}"; then
		cat "${log_path}"
		echo "Uploaded ${fixture_name} result did not carry the qit-request-footprint annotation." >&2
		exit 1
	fi

	# A pattern starting with ! must not match any line.
	local expected_pattern
	for expected_pattern in "$@"; do
		if [[ "${expected_pattern}" == '!'* ]]; then
			if grep -Eq -- "${expected_pattern#!}" "${footprint_path}"; then
				cat "${footprint_path}"
				echo "Request footprint fixture ${fixture_name} report matched what it must not: ${expected_pattern#!}" >&2
				exit 1
			fi
		elif ! grep -Eq -- "${expected_pattern}" "${footprint_path}"; then
			cat "${footprint_path}"
			echo "Request footprint fixture ${fixture_name} report did not match: ${expected_pattern}" >&2
			exit 1
		fi
	done
}

# QIT installs a --zip fixture under the known SUT's slug, so that is the directory work is
# attributed to. The report keeps the lowest count across repeats.
expect_footprint qit-request-footprint-ungated \
	'^state: complete$' \
	'^admin-ajax: [1-9][0-9]* queries, 1 writes, 1 HTTP calls$' \
	'^wp-admin-profile: [1-9][0-9]* queries, 1 writes, 1 HTTP calls$' \
	'^front-page: 0 queries, 0 writes, 0 HTTP calls$' \
	"^  admin_init -> ${KNOWN_SUT}/qit-request-footprint-ungated\\.php:[0-9]+ \\([0-9]+ queries, 1 writes"

# More read sites than the recorder keeps: the admin_init read in another file gets no call
# site, and only its hook total says where it runs.
expect_footprint qit-request-footprint-many-sites \
	'^state: complete$' \
	'^admin-ajax: 23 queries, 0 writes, 0 HTTP calls$' \
	'^admin-ajax omitted call sites: 13$' \
	'^admin-ajax hook init: 22 queries, 0 writes, 0 HTTP calls$' \
	'^admin-ajax hook admin_init: 1 queries, 0 writes, 0 HTTP calls$' \
	"^admin-ajax hook admin_init in ${KNOWN_SUT}/includes/late\\.php: 1 queries, 0 writes, 0 HTTP calls$" \
	'!^  admin_init -> '

expect_footprint qit-request-footprint-gated \
	'^state: complete$' \
	'^admin-ajax: 0 queries, 0 writes, 0 HTTP calls$' \
	'^wp-admin-profile: 0 queries, 0 writes, 0 HTTP calls$' \
	'^front-page: 0 queries, 0 writes, 0 HTTP calls$'

echo "Activation request footprint known-answer checks passed."
