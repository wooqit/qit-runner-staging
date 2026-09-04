import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import requests

from qit_api_fuzz.campaign import (
    _account_replay,
    _confirmed_findings,
    _curl,
    _minimize_json_record,
    _profile_depth,
    _replay,
    _reset,
    _reset_overhead,
    _rest_api_url,
    _run_profile,
    _schemathesis_report_completed,
    run_campaign,
)
from qit_api_fuzz.request_body import INLINE_BODY_LIMIT, capture_request_body


class CampaignTest(unittest.TestCase):

    @patch("qit_api_fuzz.campaign._replay")
    def test_large_json_finding_is_reduced_and_clean_state_verified(self, replay):
        fatal = {
            "type": "php_fatal",
            "error_type": "TypeError",
            "error_message": "Boom",
            "error_file": "/var/www/html/wp-includes/rest-api.php",
            "error_line": 1703,
        }
        original_value = {"availability": None, "noise": "x" * 600}
        record = {
            "type": "interaction",
            "profile": "administrator",
            "method": "POST",
            "route": "/test/v1/items",
            "url": "http://qit.test/wp-json/test/v1/items",
            "headers": {"Content-Type": "application/json"},
            "body": json.dumps(original_value),
            "response_status": 500,
            "instrumentation_events": [fatal],
        }

        def replay_candidate(candidate, *_args):
            value = json.loads(candidate["body"])
            reproduced = value in (original_value, {"availability": None})
            return {
                "status": "completed",
                "reproduced": reproduced,
                "request_attempted": True,
                "reset_performed": True,
                "reset_seconds": 0.1,
                "response_status": 500 if reproduced else 400,
                "events": [fatal] if reproduced else [],
            }

        replay.side_effect = replay_candidate
        minimized, evidence = _minimize_json_record(
            record,
            "/path/to/qit",
            "qitenv123",
            "http://qit.test",
            None,
            None,
            2500,
            None,
            None,
        )

        self.assertEqual('{"availability":null}', minimized["body"])
        self.assertEqual("completed", evidence["status"])
        self.assertGreater(evidence["original_bytes"], evidence["minimized_bytes"])
        self.assertEqual(2, evidence["clean_state_replays"])
        self.assertGreaterEqual(replay.call_count, 4)

    @patch("qit_api_fuzz.campaign._replay")
    def test_json_reduction_rejects_a_different_fatal(self, replay):
        original_fatal = {
            "type": "php_fatal",
            "error_type": "TypeError",
            "error_file": "/var/www/html/wp-includes/rest-api.php",
            "error_line": 1703,
        }
        different_fatal = {
            **original_fatal,
            "error_line": 999,
        }
        record = {
            "method": "POST",
            "route": "/test/v1/items",
            "url": "http://qit.test/wp-json/test/v1/items",
            "body": json.dumps({"availability": None, "noise": "x" * 600}),
            "response_status": 500,
            "instrumentation_events": [original_fatal],
        }
        replay.return_value = {
            "status": "completed",
            "reproduced": True,
            "request_attempted": True,
            "reset_performed": True,
            "reset_seconds": 0.1,
            "response_status": 500,
            "events": [different_fatal],
        }

        minimized, evidence = _minimize_json_record(
            record,
            "/path/to/qit",
            "qitenv123",
            "http://qit.test",
            None,
            None,
            2500,
            None,
            None,
        )

        self.assertIs(record, minimized)
        self.assertEqual("not_reduced", evidence["status"])
        self.assertEqual(evidence["original_bytes"], evidence["minimized_bytes"])

    @patch("qit_api_fuzz.campaign.subprocess.run")
    def test_administrator_generation_uses_configured_credentials(self, run):
        run.return_value.returncode = 0
        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "QIT_API_FUZZ_ADMIN_USER": "configured-admin",
                "QIT_API_FUZZ_ADMIN_PASSWORD": "configured-password",
            },
        ):
            root = Path(directory)
            outcome, return_code = _run_profile(
                "administrator",
                root / "openapi.json",
                root,
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                100,
                10,
                60,
            )

        command = run.call_args.args[0]
        self.assertEqual("completed", outcome)
        self.assertEqual(0, return_code)
        self.assertEqual(
            "configured-admin:configured-password",
            command[command.index("--auth") + 1],
        )
        self.assertEqual("http://qit.test/wp-json", command[command.index("--url") + 1])
        self.assertEqual("fuzzing", command[command.index("--phases") + 1])
        self.assertNotIn("coverage", command[command.index("--phases") + 1])
        self.assertEqual("operation", run.call_args.kwargs["env"]["QIT_API_FUZZ_RESET_MODE"])

    def test_rest_api_url_is_normalized_once(self):
        self.assertEqual("http://qit.test/wp-json", _rest_api_url("http://qit.test/"))
        self.assertEqual("http://qit.test/wp-json", _rest_api_url("http://qit.test/wp-json"))

    def test_reproduction_curl_is_readable_adaptable_and_omits_harness_noise(self):
        command = _curl(
            {
                "profile": "administrator",
                "method": "POST",
                "url": "http://localhost:32768/wp-json/test/v1/items?force=true",
                "headers": {
                    "Accept": "*/*",
                    "Accept-Encoding": "gzip, deflate",
                    "Authorization": "Basic secret",
                    "Connection": "keep-alive",
                    "Content-Type": "application/json",
                    "User-Agent": "schemathesis/4.22.4",
                    "X-QIT-API-Fuzz": "1",
                    "X-QIT-API-Fuzz-Request-ID": "request-id",
                    "X-QIT-API-Fuzz-Request-Number": "723",
                    "X-Schemathesis-TestCaseId": "case-id",
                },
                "body": '{"id":0}',
            }
        )

        self.assertIn("curl --request POST \\\n", command)
        self.assertIn('--user "${QIT_ADMIN_USER}:${QIT_ADMIN_PASSWORD}"', command)
        self.assertIn("--header 'Content-Type: application/json'", command)
        self.assertIn("--header 'X-QIT-API-Fuzz: 1'", command)
        self.assertIn("--data-binary '{\"id\":0}'", command)
        self.assertIn('"${QIT_SITE_URL}/wp-json/test/v1/items?force=true"', command)
        self.assertNotIn("Basic secret", command)
        self.assertNotIn("Accept-Encoding", command)
        self.assertNotIn("Connection", command)
        self.assertNotIn("Request-ID", command)
        self.assertNotIn("Request-Number", command)
        self.assertNotIn("TestCaseId", command)

    @patch("qit_api_fuzz.campaign.requests.get")
    @patch("qit_api_fuzz.campaign.requests.request")
    @patch("qit_api_fuzz.campaign._reset", return_value=None)
    def test_configured_credentials_preserve_http_200_fatal_attribution(
        self, reset, request, get
    ):
        fatal = {
            "type": "php_fatal",
            "error_type": 1,
            "error_message": "Boom",
            "error_file": "/var/www/html/wp-content/plugins/test-plugin/controller.php",
            "error_line": 42,
        }
        request.return_value.status_code = 200
        get.return_value.ok = True
        get.return_value.json.return_value = [fatal]
        record = {
            "type": "interaction",
            "profile": "administrator",
            "method": "POST",
            "route": "/test/v1/fatal-after-response",
            "url": "http://qit.test/wp-json/test/v1/fatal-after-response",
            "headers": {"Content-Type": "application/json"},
            "body": "{}",
            "response_status": 200,
            "instrumentation_events": [fatal],
        }

        with patch.dict(
            os.environ,
            {
                "QIT_API_FUZZ_ADMIN_USER": "configured-admin",
                "QIT_API_FUZZ_ADMIN_PASSWORD": "configured-password",
            },
        ):
            findings, anomalies = _confirmed_findings(
                [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
            )

        expected_auth = ("configured-admin", "configured-password")
        self.assertEqual(2, reset.call_count)
        self.assertEqual(2, request.call_count)
        self.assertTrue(all(call.kwargs["auth"] == expected_auth for call in request.call_args_list))
        self.assertEqual(2, get.call_count)
        self.assertTrue(all(call.kwargs["auth"] == expected_auth for call in get.call_args_list))
        self.assertEqual([], anomalies)
        self.assertEqual(1, len(findings))
        self.assertEqual("php_fatal", findings[0]["finding_type"])
        self.assertTrue(findings[0]["is_sut_attributed"])
        self.assertEqual(200, findings[0]["confirmation"]["responses"][0])

    @patch("qit_api_fuzz.campaign._poll_events")
    @patch("qit_api_fuzz.campaign.requests.request")
    @patch("qit_api_fuzz.campaign._reset", return_value=None)
    def test_large_body_fatal_replays_from_exact_private_artifact(self, reset, request, poll_events):
        fatal = {
            "type": "php_fatal",
            "error_type": 1,
            "error_message": "Large payload fatal",
            "error_file": "/var/www/html/wp-content/plugins/test-plugin/controller.php",
            "error_line": 42,
        }
        body = ("large-payload-" * (INLINE_BODY_LIMIT // 14 + 1)).encode("utf-8")
        request.return_value.status_code = 200
        poll_events.return_value = ([fatal], None)

        with tempfile.TemporaryDirectory() as directory:
            artifact_root = Path(directory)
            body_record = capture_request_body(body, artifact_root)
            record = {
                "type": "interaction",
                "profile": "administrator",
                "method": "POST",
                "route": "/test/v1/large-fatal",
                "url": "http://qit.test/wp-json/test/v1/large-fatal",
                "headers": {"Content-Type": "application/octet-stream"},
                "response_status": 200,
                "instrumentation_events": [fatal],
                **body_record,
            }

            findings, anomalies = _confirmed_findings(
                [record],
                "/path/to/qit",
                "qitenv123",
                "http://qit.test",
                "test-plugin",
                artifact_root / "request-count.txt",
            )

        self.assertEqual(2, reset.call_count)
        self.assertEqual(2, request.call_count)
        self.assertTrue(all(call.kwargs["data"] == body for call in request.call_args_list))
        self.assertEqual([], anomalies)
        self.assertEqual(1, len(findings))
        self.assertTrue(findings[0]["is_sut_attributed"])
        self.assertIn("--data-binary @request-bodies/", findings[0]["redacted_request"])
        self.assertNotIn("large-payload-large-payload", findings[0]["redacted_request"])

    @patch("qit_api_fuzz.campaign.requests.request")
    @patch("qit_api_fuzz.campaign._reset", return_value=None)
    def test_missing_large_body_artifact_is_an_infrastructure_error(self, reset, request):
        digest = "a" * 64
        with tempfile.TemporaryDirectory() as directory:
            replay = _replay(
                {
                    "method": "POST",
                    "url": "http://qit.test/wp-json/test/v1/large-fatal",
                    "body_file": f"request-bodies/{digest}.bin",
                    "body_size": INLINE_BODY_LIMIT + 1,
                    "body_sha256": digest,
                },
                "/path/to/qit",
                "qitenv123",
                "http://qit.test",
                Path(directory),
            )

        self.assertEqual("infrastructure_error", replay["status"])
        self.assertEqual("request_body_artifact_missing", replay["reason"])
        self.assertFalse(replay["request_attempted"])
        reset.assert_not_called()
        request.assert_not_called()

    @patch("qit_api_fuzz.campaign._reset", return_value="clean_state_restore_failed")
    def test_replay_reset_failure_is_an_infrastructure_error(self, reset):
        replay = _replay({}, "/path/to/qit", "qitenv123", "http://qit.test")

        self.assertEqual("infrastructure_error", replay["status"])
        self.assertIsNone(replay["reproduced"])
        self.assertFalse(replay["request_attempted"])
        self.assertEqual("clean_state_restore_failed", replay["reason"])
        reset.assert_called_once()

    @patch("qit_api_fuzz.campaign._reset")
    def test_confirmation_reset_attempt_is_appended_to_private_ledger(self, reset):
        reset.return_value = {
            "status": "infrastructure_error",
            "reason": "clean_state_object_cache_flush_failed",
            "reset_performed": True,
            "seconds": 1.0,
            "cli_seconds": 0.9,
            "caller_overhead_seconds": 0.1,
            "strategy": "container_staged",
            "failed_phase": "object_cache_flush",
            "message": "flush failed",
            "phases": {},
        }
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            replay = _replay(
                {"profile": "administrator", "operation": "POST /items"},
                "/path/to/qit",
                "qitenv123",
                "http://qit.test",
                root,
            )
            records = [
                json.loads(line)
                for line in (root / "interactions.jsonl").read_text().splitlines()
            ]

        self.assertEqual("clean_state_object_cache_flush_failed", replay["reason"])
        self.assertEqual(1, len(records))
        self.assertEqual("reset", records[0]["type"])
        self.assertEqual("confirmation", records[0]["scope"])
        self.assertEqual("object_cache_flush", records[0]["failed_phase"])

    @patch("qit_api_fuzz.campaign._reset")
    def test_confirmation_exec_error_is_counted_without_claiming_reset_performed(self, reset):
        observation = {
            "status": "infrastructure_error",
            "reason": "clean_state_restore_exec_error",
            "reset_performed": False,
            "seconds": 0.25,
            "cli_seconds": 0.0,
            "caller_overhead_seconds": 0.0,
            "strategy": "unknown",
            "failed_phase": None,
            "message": "qit not found",
            "phases": {},
        }
        reset.return_value = observation

        replay = _replay({}, "/missing/qit", "qitenv123", "http://qit.test")
        stats = {}
        _account_replay(replay, None, stats)

        self.assertFalse(replay["reset_performed"])
        self.assertEqual(1, stats["reset_count"])
        self.assertEqual(0.25, stats["reset_seconds"])
        self.assertEqual([observation], stats["reset_observations"])

    @patch("qit_api_fuzz.campaign.requests.request", side_effect=requests.Timeout("timed out"))
    @patch("qit_api_fuzz.campaign._reset", return_value=None)
    def test_replay_transport_failure_is_an_infrastructure_error(self, reset, request):
        replay = _replay(
            {"method": "GET", "url": "http://qit.test/wp-json/test/v1/items"},
            "/path/to/qit",
            "qitenv123",
            "http://qit.test",
        )

        self.assertEqual("infrastructure_error", replay["status"])
        self.assertIsNone(replay["reproduced"])
        self.assertTrue(replay["request_attempted"])
        self.assertEqual("Timeout", replay["reason"])
        reset.assert_called_once()
        request.assert_called_once()

    @patch("qit_api_fuzz.campaign._poll_events", return_value=([], "instrumentation_http_503"))
    @patch("qit_api_fuzz.campaign.requests.request")
    @patch("qit_api_fuzz.campaign._reset", return_value=None)
    def test_replay_instrumentation_failure_is_an_infrastructure_error(
        self, reset, request, poll_events
    ):
        request.return_value.status_code = 200

        replay = _replay(
            {"method": "GET", "url": "http://qit.test/wp-json/test/v1/items"},
            "/path/to/qit",
            "qitenv123",
            "http://qit.test",
        )

        self.assertEqual("infrastructure_error", replay["status"])
        self.assertIsNone(replay["reproduced"])
        self.assertTrue(replay["request_attempted"])
        self.assertEqual("instrumentation_http_503", replay["reason"])
        reset.assert_called_once()
        request.assert_called_once()
        poll_events.assert_called_once()

    @patch(
        "qit_api_fuzz.reset.subprocess.run",
        side_effect=subprocess.TimeoutExpired(cmd=["qit", "env:reset"], timeout=45),
    )
    def test_reset_timeout_returns_an_infrastructure_reason(self, run):
        observation = _reset("/path/to/qit", "qitenv123")

        self.assertEqual("infrastructure_error", observation["status"])
        self.assertEqual("clean_state_restore_timeout", observation["reason"])
        run.assert_called_once()

    def test_schemathesis_check_failure_is_a_completed_tool_run(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "report.ndjson"
            report.write_text(
                '{"Initialize":{}}\n'
                '{"ScenarioFinished":{"status":"failure"}}\n'
                '{"EngineFinished":{"stop_reason":"finished"}}\n',
                encoding="utf-8",
            )

            self.assertTrue(_schemathesis_report_completed(report))

            report.write_text(
                '{"Initialize":{}}\n{"FatalError":{"exception":"hook failed"}}\n',
                encoding="utf-8",
            )
            self.assertFalse(_schemathesis_report_completed(report))

    def test_no_route_delta_is_not_applicable_without_invoking_schemathesis(self):
        route_export = {"schema_version": "1.0.0", "routes": {}}
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            sut_routes = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(json.dumps(route_export), encoding="utf-8")
            sut_routes.write_text(json.dumps(route_export), encoding="utf-8")

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

            self.assertEqual("not_applicable", result["campaign"]["state"])
            self.assertEqual(0, result["discovery"]["target_operation_count"])
            self.assertEqual([], result["errors"])
            self.assertEqual(result, json.loads(output.read_text(encoding="utf-8")))

    @patch("qit_api_fuzz.campaign._run_profile")
    def test_shared_modified_routes_are_reported_but_not_fuzzed(self, run_profile):
        baseline_routes = {
            "routes": {
                "/wc/store/checkout": [
                    {
                        "methods": ["POST"],
                        "callback": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce/src/StoreApi/Checkout.php"
                        },
                        "args": {"billing_address": {"type": "object"}},
                    }
                ]
            }
        }
        sut_routes = {
            "routes": {
                "/wc/store/checkout": [
                    {
                        "methods": ["POST"],
                        "callback": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce/src/StoreApi/Checkout.php"
                        },
                        "args": {
                            "billing_address": {"type": "object"},
                            "booking_data": {"type": "object"},
                        },
                    }
                ]
            }
        }
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            with_sut = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(json.dumps(baseline_routes), encoding="utf-8")
            with_sut.write_text(json.dumps(sut_routes), encoding="utf-8")

            result = run_campaign(
                baseline,
                with_sut,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "woocommerce-bookings", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

        run_profile.assert_not_called()
        self.assertEqual("not_applicable", result["campaign"]["state"])
        self.assertEqual("no_sut_owned_rest_operations", result["campaign"]["stop_reason"])
        self.assertEqual(0, result["discovery"]["target_operation_count"])
        self.assertEqual(1, result["discovery"]["shared_modified_operation_count"])
        self.assertEqual(
            "POST /wc/store/checkout",
            result["discovery"]["shared_modified"][0]["operation"],
        )
        self.assertEqual(["args"], result["discovery"]["shared_modified"][0]["changed_fields"])

    def test_discovered_but_unrepresentable_routes_are_unavailable(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            sut_routes = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(
                json.dumps(
                    {
                        "routes": {
                            "/example/v1/(?P<kind>foo|bar)/(?:(?P<id>[0-9]+))?": [
                                {"methods": ["GET"], "args": {}}
                            ]
                        }
                    }
                ),
                encoding="utf-8",
            )

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

            self.assertEqual("unavailable", result["campaign"]["state"])
            self.assertEqual("no_usable_schema_operations", result["campaign"]["stop_reason"])
            self.assertEqual(1, result["schema"]["unsupported"])

    @patch("qit_api_fuzz.campaign._run_profile")
    def test_colliding_openapi_paths_are_unavailable_without_running_schemathesis(self, run_profile):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            sut_routes = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(
                json.dumps(
                    {
                        "routes": {
                            "/example/v1/items/(?P<id>\\d+)": [
                                {"methods": ["GET"], "args": {"id": {"type": "integer"}}}
                            ],
                            "/example/v1/items/(?P<id>[0-9a-f]+)": [
                                {"methods": ["GET"], "args": {"id": {"type": "string"}}}
                            ],
                        }
                    }
                ),
                encoding="utf-8",
            )

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

        run_profile.assert_not_called()
        self.assertEqual("unavailable", result["campaign"]["state"])
        self.assertEqual("no_usable_schema_operations", result["campaign"]["stop_reason"])
        self.assertEqual(2, result["schema"]["unsupported"])
        self.assertEqual(0, result["schema"]["complete"])

    @patch("qit_api_fuzz.campaign._read_ledger")
    @patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0))
    def test_scheduler_runs_added_before_modified_for_both_profiles(
        self, run_profile, read_ledger
    ):
        operations = ("GET /test/v1/added", "POST /test/v1/modified")
        read_ledger.return_value = [
            {
                "type": "interaction",
                "profile": profile,
                "operation": operation,
                "method": operation.split(" ", 1)[0],
                "route": operation.split(" ", 1)[1],
                "url": "http://qit.test/wp-json" + operation.split(" ", 1)[1],
                "headers": {},
                "response_status": 200,
                "instrumentation_events": [],
            }
            for profile in ("anonymous", "administrator")
            for operation in operations
        ]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            sut_routes = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(
                json.dumps(
                    {
                        "routes": {
                            "/test/v1/modified": [
                                {
                                    "methods": ["POST"],
                                    "callback": {
                                        "file": "/var/www/html/wp-content/plugins/woocommerce/controller.php"
                                    },
                                    "args": {"id": {"type": "integer"}},
                                }
                            ]
                        }
                    }
                ),
                encoding="utf-8",
            )
            sut_routes.write_text(
                json.dumps(
                    {
                        "routes": {
                            "/test/v1/added": [
                                {"methods": ["GET"], "args": {"q": {"type": "string"}}}
                            ],
                            "/test/v1/modified": [
                                {
                                    "methods": ["POST"],
                                    "callback": {
                                        "file": "/var/www/html/wp-content/plugins/test-plugin/controller.php"
                                    },
                                    "args": {"id": {"type": "string"}},
                                }
                            ],
                        }
                    }
                ),
                encoding="utf-8",
            )

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

        stage_names = [call.args[9] for call in run_profile.call_args_list]
        self.assertEqual(
            [
                "anonymous-added-breadth",
                "administrator-added-breadth",
                "anonymous-modified-breadth",
                "administrator-modified-breadth",
            ],
            stage_names[:4],
        )
        self.assertEqual("completed", result["campaign"]["state"])
        self.assertEqual(
            "sut_added_then_modified_breadth_first",
            result["campaign"]["scheduling"]["strategy"],
        )
        self.assertEqual(100.0, result["campaign"]["scheduling"]["coverage"]["coverage_percent"])
        for profile in ("anonymous", "administrator"):
            depth = result["campaign"]["scheduling"]["depth"]["per_profile"][profile]
            self.assertEqual(2, depth["examples_total"])
            self.assertEqual(1, depth["min_examples_per_operation"])
            self.assertEqual(1.0, depth["median_examples_per_operation"])

    @patch("qit_api_fuzz.campaign._replay")
    def test_only_two_replay_candidates_become_attributed_findings(self, replay):
        replay.return_value = {
            "reproduced": True,
            "response_status": 500,
            "events": [
                {
                    "type": "php_fatal",
                    "error_type": 1,
                    "error_message": "Boom",
                    "error_file": "/var/www/html/wp-content/plugins/test-plugin/controller.php",
                    "error_line": 42,
                }
            ],
        }
        record = {
            "type": "interaction",
            "profile": "administrator",
            "method": "POST",
            "route": "/test/v1/items",
            "url": "http://qit.test/wp-json/test/v1/items",
            "headers": {"Content-Type": "application/json", "Authorization": "should-not-persist"},
            "body": '{"id": 0}',
            "response_status": 500,
            "instrumentation_events": [],
        }

        findings, anomalies = _confirmed_findings(
            [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual(2, replay.call_count)
        self.assertEqual([], anomalies)
        self.assertEqual(1, len(findings))
        self.assertTrue(findings[0]["is_sut_attributed"])
        self.assertEqual(2, findings[0]["confirmation"]["reproduced"])
        self.assertNotIn("should-not-persist", findings[0]["redacted_request"])
        self.assertIn('${QIT_SITE_URL}/wp-json/test/v1/items', findings[0]["redacted_request"])
        self.assertIn('${QIT_ADMIN_USER}:${QIT_ADMIN_PASSWORD}', findings[0]["redacted_request"])
        self.assertEqual(64, len(findings[0]["fingerprint"]))

    @patch("qit_api_fuzz.campaign._replay")
    def test_expected_domain_5xx_is_an_anomaly_without_confirmation_replay(self, replay):
        records = [
            {
                "type": "interaction",
                "profile": profile,
                "method": "DELETE",
                "route": "/wc/v3/subscriptions/1/notes/2",
                "url": "http://qit.test/wp-json/wc/v3/subscriptions/1/notes/2",
                "headers": {},
                "response_status": 501,
                "response_error_code": "woocommerce_rest_trash_not_supported",
                "instrumentation_events": [],
            }
            for profile in ("anonymous", "administrator")
        ]

        findings, anomalies = _confirmed_findings(
            records, "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        replay.assert_not_called()
        self.assertEqual([], findings)
        self.assertEqual(1, len(anomalies))
        self.assertEqual("expected_5xx_response_ignored", anomalies[0]["type"])
        self.assertEqual(
            "woocommerce_rest_trash_not_supported",
            anomalies[0]["response_error_code"],
        )

    @patch("qit_api_fuzz.campaign._replay")
    def test_unknown_5xx_code_is_still_confirmed_as_a_finding(self, replay):
        replay.return_value = {
            "reproduced": True,
            "response_status": 501,
            "events": [],
        }
        record = {
            "type": "interaction",
            "profile": "administrator",
            "method": "DELETE",
            "route": "/test/v1/items/1",
            "url": "http://qit.test/wp-json/test/v1/items/1",
            "headers": {},
            "response_status": 501,
            "response_error_code": "unexpected_server_failure",
            "instrumentation_events": [],
        }

        findings, anomalies = _confirmed_findings(
            [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual(2, replay.call_count)
        self.assertEqual([], anomalies)
        self.assertEqual(1, len(findings))

    @patch("qit_api_fuzz.campaign._replay")
    def test_flaky_candidate_is_an_anomaly_not_a_finding(self, replay):
        replay.side_effect = [
            {"reproduced": True, "response_status": 500, "events": []},
            {"reproduced": False, "response_status": 200, "events": []},
        ]
        record = {
            "type": "interaction",
            "profile": "anonymous",
            "method": "GET",
            "route": "/test/v1/flaky",
            "url": "http://qit.test/wp-json/test/v1/flaky",
            "headers": {},
            "response_status": 500,
            "instrumentation_events": [],
        }

        findings, anomalies = _confirmed_findings(
            [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual([], findings)
        self.assertEqual("non_reproducible_candidate", anomalies[0]["type"])

    @patch("qit_api_fuzz.campaign._replay")
    def test_transient_confirmation_infrastructure_error_is_retried(self, replay):
        replay.side_effect = [
            {
                "status": "infrastructure_error",
                "reproduced": None,
                "request_attempted": False,
                "reason": "clean_state_restore_failed",
            },
            {"status": "completed", "reproduced": True, "response_status": 500, "events": []},
            {"status": "completed", "reproduced": True, "response_status": 500, "events": []},
        ]
        record = {
            "type": "interaction",
            "profile": "anonymous",
            "method": "GET",
            "route": "/test/v1/fatal",
            "url": "http://qit.test/wp-json/test/v1/fatal",
            "headers": {},
            "response_status": 500,
            "instrumentation_events": [],
        }

        findings, anomalies = _confirmed_findings(
            [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual(3, replay.call_count)
        self.assertEqual([], anomalies)
        self.assertEqual(1, len(findings))
        self.assertEqual(3, findings[0]["confirmation"]["attempts"])

    @patch("qit_api_fuzz.campaign._replay")
    def test_confirmation_infrastructure_exhaustion_is_not_non_reproducible(self, replay):
        replay.return_value = {
            "status": "infrastructure_error",
            "reproduced": None,
            "request_attempted": False,
            "reason": "clean_state_restore_failed",
        }
        record = {
            "type": "interaction",
            "profile": "anonymous",
            "method": "GET",
            "route": "/test/v1/fatal",
            "url": "http://qit.test/wp-json/test/v1/fatal",
            "headers": {},
            "response_status": 500,
            "instrumentation_events": [],
        }

        findings, anomalies = _confirmed_findings(
            [record], "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual(4, replay.call_count)
        self.assertEqual([], findings)
        self.assertEqual("confirmation_infrastructure_error", anomalies[0]["type"])
        self.assertEqual(0, anomalies[0]["completed_replays"])
        self.assertNotIn("non_reproducible_candidate", {anomaly["type"] for anomaly in anomalies})

    @patch("qit_api_fuzz.campaign._replay")
    def test_confirmation_timeout_preserves_findings_from_other_candidates(self, replay):
        replay.side_effect = [
            {
                "status": "completed",
                "reproduced": True,
                "response_status": 500,
                "events": [],
            },
            {
                "status": "completed",
                "reproduced": True,
                "response_status": 500,
                "events": [],
            },
        ] + [
            {
                "status": "infrastructure_error",
                "reproduced": None,
                "request_attempted": False,
                "reason": "clean_state_restore_timeout",
            }
        ] * 4
        records = [
            {
                "type": "interaction",
                "profile": "anonymous",
                "method": "GET",
                "route": "/test/v1/confirmed",
                "url": "http://qit.test/wp-json/test/v1/confirmed",
                "headers": {},
                "response_status": 500,
                "instrumentation_events": [],
            },
            {
                "type": "interaction",
                "profile": "anonymous",
                "method": "GET",
                "route": "/test/v1/reset-timeout",
                "url": "http://qit.test/wp-json/test/v1/reset-timeout",
                "headers": {},
                "response_status": 500,
                "instrumentation_events": [],
            },
        ]

        findings, anomalies = _confirmed_findings(
            records, "/path/to/qit", "qitenv123", "http://qit.test", "test-plugin"
        )

        self.assertEqual(6, replay.call_count)
        self.assertEqual(1, len(findings))
        self.assertEqual("/test/v1/confirmed", findings[0]["route"])
        self.assertEqual(1, len(anomalies))
        self.assertEqual("confirmation_infrastructure_error", anomalies[0]["type"])
        self.assertEqual(["clean_state_restore_timeout"] * 4, anomalies[0]["errors"])

    @patch(
        "qit_api_fuzz.reset.subprocess.run",
        side_effect=subprocess.TimeoutExpired(cmd=["qit", "env:reset"], timeout=45),
    )
    @patch("qit_api_fuzz.campaign._read_ledger")
    @patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0))
    def test_confirmation_reset_timeout_makes_campaign_partial(
        self, run_profile, read_ledger, reset_run
    ):
        read_ledger.return_value = [
            {
                "type": "interaction",
                "profile": "anonymous",
                "method": "GET",
                "route": "/test/v1/fatal",
                "url": "http://qit.test/wp-json/test/v1/fatal",
                "headers": {},
                "operation": "GET /test/v1/fatal",
                "response_status": 500,
                "instrumentation_events": [],
            }
        ]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline.json"
            sut_routes = root / "sut.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(
                json.dumps(
                    {
                        "routes": {
                            "/test/v1/fatal": [
                                {"methods": ["GET"], "args": {"id": {"type": "integer"}}}
                            ]
                        }
                    }
                ),
                encoding="utf-8",
            )

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                "http://qit.test",
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

        # Two breadth stages plus one optional depth stage for each auth profile.
        self.assertEqual(4, run_profile.call_count)
        self.assertEqual("partial", result["campaign"]["state"])
        self.assertEqual("confirmation_infrastructure_error", result["campaign"]["stop_reason"])
        self.assertEqual([], result["findings"])
        self.assertEqual(4, reset_run.call_count)
        self.assertEqual("confirmation_infrastructure_error", result["anomalies"][0]["type"])
        self.assertEqual(
            ["clean_state_restore_timeout"] * 4,
            result["anomalies"][0]["errors"],
        )


class OverheadAndDifferentialTest(unittest.TestCase):
    FATAL = {
        "type": "php_fatal",
        "error_type": 1,
        "error_message": "Boom",
        "error_file": "/var/www/html/wp-content/plugins/test-plugin/controller.php",
        "error_line": 42,
    }

    def _run(self, ledger, sut_routes, env=None):
        with patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0)), patch(
            "qit_api_fuzz.campaign._read_ledger", return_value=ledger
        ), patch("qit_api_fuzz.campaign._replay") as replay, patch.dict(os.environ, env or {}):
            replay.return_value = {
                "status": "completed",
                "reproduced": True,
                "request_attempted": True,
                "reset_performed": True,
                "reset_seconds": 0.1,
                "response_status": 500,
                "events": [self.FATAL],
            }
            with tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                baseline = root / "baseline.json"
                sut = root / "sut.json"
                output = root / "result.json"
                baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
                sut.write_text(json.dumps({"routes": sut_routes}), encoding="utf-8")
                return run_campaign(
                    baseline,
                    sut,
                    output,
                    root / "artifacts",
                    "http://qit.test",
                    "/path/to/qit",
                    "qitenv123",
                    {"woo_id": 123, "slug": "test-plugin", "version": "1.0.0"},
                    {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
                )

    def test_overhead_sums_generation_and_confirmation_resets(self):
        ledger = [
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "GET /test/v1/fatal",
                "method": "GET",
                "route": "/test/v1/fatal",
                "url": "http://qit.test/wp-json/test/v1/fatal",
                "headers": {},
                "response_status": 500,
                "reset_performed": True,
                "reset_seconds": 0.05,
                "instrumentation_events": [],
            },
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "GET /test/v1/fatal",
                "method": "GET",
                "route": "/test/v1/fatal",
                "url": "http://qit.test/wp-json/test/v1/fatal",
                "headers": {},
                "response_status": 200,
                "reset_performed": False,
                "reset_seconds": 0.0,
                "instrumentation_events": [],
            },
        ]
        sut_routes = {"/test/v1/fatal": [{"methods": ["GET"], "args": {"id": {"type": "integer"}}}]}
        result = self._run(ledger, sut_routes)
        overhead = result["campaign"]["overhead"]
        # One generation reset (0.05s) + two confirmation replays (0.1s each).
        self.assertEqual(3, overhead["reset_count"])
        self.assertEqual(0.25, overhead["reset_seconds_total"])
        self.assertIsInstance(overhead["generation_seconds"], float)
        self.assertIsInstance(overhead["confirmation_seconds"], float)

    def test_reset_overhead_includes_failed_attempts_and_phase_distributions(self):
        def observation(seconds, database_seconds, cache_seconds, status="completed"):
            return {
                "status": status,
                "seconds": seconds,
                "caller_overhead_seconds": 0.1,
                "strategy": "container_staged",
                "phases": {
                    "environment_lookup": {"status": "completed", "seconds": 0.01},
                    "snapshot_copy": {"status": "skipped", "seconds": 0.0},
                    "database_import": {
                        "status": "failed" if status != "completed" else "completed",
                        "seconds": database_seconds,
                    },
                    "temporary_file_cleanup": {"status": "skipped", "seconds": 0.0},
                    "object_cache_flush": {
                        "status": "not_started" if status != "completed" else "completed",
                        "seconds": cache_seconds,
                    },
                },
            }

        overhead = _reset_overhead(
            [
                observation(1.0, 0.7, 0.1),
                observation(2.0, 1.6, 0.2),
                observation(3.0, 2.8, 0.0, "infrastructure_error"),
            ]
        )

        self.assertEqual(3, overhead["reset_count"])
        self.assertEqual(2.0, overhead["reset_seconds_median"])
        self.assertEqual(3.0, overhead["reset_seconds_p95"])
        self.assertEqual("container_staged", overhead["reset_strategy"])
        self.assertEqual("database_import", overhead["reset_limiting_phase"])
        self.assertEqual(1, overhead["reset_phases"]["database_import"]["failed_count"])
        self.assertEqual(1, overhead["reset_phases"]["object_cache_flush"]["not_started_count"])
        self.assertEqual(0.3, overhead["reset_phases"]["caller_overhead"]["seconds_total"])

    def test_protocol_error_duration_is_not_reported_as_caller_overhead(self):
        observations = [
            {
                "status": "infrastructure_error",
                "reason": reason,
                "reset_performed": performed,
                "seconds": 45.0,
                "cli_seconds": 0.0,
                "caller_overhead_seconds": 45.0,
                "strategy": "unknown",
                "failed_phase": None,
                "message": "",
                "phases": {},
            }
            for reason, performed in (
                ("clean_state_restore_timeout", True),
                ("clean_state_restore_exec_error", False),
            )
        ]

        overhead = _reset_overhead(observations)

        self.assertEqual(2, overhead["reset_count"])
        self.assertEqual(90.0, overhead["reset_seconds_total"])
        self.assertIsNone(overhead["reset_limiting_phase"])
        self.assertEqual(0.0, overhead["reset_phases"]["caller_overhead"]["seconds_total"])
        self.assertEqual(0, overhead["reset_phases"]["caller_overhead"]["completed_count"])
        self.assertEqual(2, overhead["reset_phases"]["caller_overhead"]["not_started_count"])

    def test_profile_depth_includes_zero_count_usable_operations(self):
        depth = _profile_depth(
            [
                {"operation": "GET /one"},
                {"operation": "GET /one"},
                {"operation": "POST /two"},
                {"operation": "GET /outside"},
            ],
            {"GET /one", "POST /two", "DELETE /zero"},
        )

        self.assertEqual(3, depth["examples_total"])
        self.assertEqual(3, depth["usable_operations"])
        self.assertEqual(0, depth["min_examples_per_operation"])
        self.assertEqual(1.0, depth["median_examples_per_operation"])
        self.assertEqual(2.0, depth["p95_examples_per_operation"])
        self.assertEqual(2, depth["max_examples_per_operation"])

    def test_operation_batch_is_recorded_in_isolation_scope(self):
        sut_routes = {"/test/v1/clean": [{"methods": ["GET"], "args": {"id": {"type": "integer"}}}]}
        ledger = [
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "GET /test/v1/clean",
                "method": "GET",
                "route": "/test/v1/clean",
                "url": "http://qit.test/wp-json/test/v1/clean",
                "headers": {},
                "response_status": 200,
                "reset_performed": True,
                "reset_seconds": 0.05,
                "instrumentation_events": [],
            }
        ]
        result = self._run(ledger, sut_routes)
        isolation = result["campaign"]["isolation"]
        self.assertEqual("operation_batch", isolation["generation_reset_strategy"])
        self.assertIn("generated_operation_batch", isolation["database"])
        self.assertNotIn("generation_reset_every", isolation)

    def test_anonymous_write_reaching_a_callback_is_a_differential_observation(self):
        ledger = [
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "POST /test/v1/orders",
                "method": "POST",
                "route": "/test/v1/orders",
                "url": "http://qit.test/wp-json/test/v1/orders",
                "headers": {},
                "response_status": 201,
                "instrumentation_events": [
                    {"type": "rest_callback_reached", "method": "POST", "route": "/test/v1/orders"}
                ],
            },
            # Same operation again: the observation is deduplicated by (method, route).
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "POST /test/v1/orders",
                "method": "POST",
                "route": "/test/v1/orders",
                "url": "http://qit.test/wp-json/test/v1/orders",
                "headers": {},
                "response_status": 200,
                "instrumentation_events": [
                    {"type": "rest_callback_reached", "method": "POST", "route": "/test/v1/orders"}
                ],
            },
            # Anonymous write that never reached the callback (routing/permission 200) is ignored.
            {
                "type": "interaction",
                "profile": "anonymous",
                "operation": "POST /test/v1/other",
                "method": "POST",
                "route": "/test/v1/other",
                "url": "http://qit.test/wp-json/test/v1/other",
                "headers": {},
                "response_status": 200,
                "instrumentation_events": [],
            },
            # Administrator write is expected to be accepted and is not an observation.
            {
                "type": "interaction",
                "profile": "administrator",
                "operation": "POST /test/v1/orders",
                "method": "POST",
                "route": "/test/v1/orders",
                "url": "http://qit.test/wp-json/test/v1/orders",
                "headers": {},
                "response_status": 201,
                "instrumentation_events": [
                    {"type": "rest_callback_reached", "method": "POST", "route": "/test/v1/orders"}
                ],
            },
        ]
        sut_routes = {"/test/v1/clean": [{"methods": ["GET"], "args": {"id": {"type": "integer"}}}]}
        result = self._run(ledger, sut_routes)
        observations = [a for a in result["anomalies"] if a["type"] == "anonymous_write_accepted"]
        self.assertEqual(1, len(observations))
        self.assertEqual("POST", observations[0]["method"])
        self.assertEqual("/test/v1/orders", observations[0]["route"])
        self.assertEqual([], result["findings"])


if __name__ == "__main__":
    unittest.main()
