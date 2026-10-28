"""End-to-end known-answer test for the synthetic SUT fixture.

This drives the real campaign pipeline — route diff, OpenAPI conversion, confirmation, attribution,
fingerprinting, and result-contract validation — against route exports and an interaction ledger
shaped exactly as the `qit-api-fuzz-synthetic-sut` fixture plugin produces them. Only the live-site
boundary (Schemathesis generation and the HTTP replay transport) is stubbed; everything the runner
would compute offline runs for real.

The fixture plants two faults and one clean route, so the expected outcome is fixed in advance:
a completed campaign with exactly two SUT-attributed findings and no finding for the clean route.
"""

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from qit_api_fuzz.campaign import run_campaign
from qit_api_fuzz.result import finding_fingerprint, validate_result

SUT_SLUG = "qit-api-fuzz-synthetic-sut"
FIXTURE_FILE = f"/var/www/html/wp-content/plugins/{SUT_SLUG}/{SUT_SLUG}.php"
SITE_URL = "http://qit.test"

# The three operations the fixture registers, as the MU-plugin route exporter would report them.
FIXTURE_ROUTES = {
    "/qit-fuzz-fixture/v1/deterministic-fatal": [
        {
            "methods": {"POST": True},
            "callback": {"type": "closure", "file": FIXTURE_FILE, "line": 40},
            "permission_callback": {"name": "__return_true"},
            "args": {"id": {"type": "integer", "required": False}},
            "schema": {},
        }
    ],
    "/qit-fuzz-fixture/v1/swallowed-fatal": [
        {
            "methods": {"POST": True},
            "callback": {"type": "closure", "file": FIXTURE_FILE, "line": 58},
            "permission_callback": {"name": "__return_true"},
            "args": {"note": {"type": "string", "required": False}},
            "schema": {},
        }
    ],
    "/qit-fuzz-fixture/v1/clean": [
        {
            "methods": {"GET": True},
            "callback": {"type": "closure", "file": FIXTURE_FILE, "line": 76},
            "permission_callback": {"name": "__return_true"},
            "args": {"q": {"type": "string", "required": False}},
            "schema": {},
        }
    ],
}

# The php_fatal instrumentation events the two planted faults emit, keyed by route. Distinct error
# lines give the two findings distinct fingerprints.
_FATALS = {
    "/qit-fuzz-fixture/v1/deterministic-fatal": {
        "type": "php_fatal",
        "error_type": 1,
        "error_message": "Call to a member function explode() on null",
        "error_file": FIXTURE_FILE,
        "error_line": 44,
    },
    "/qit-fuzz-fixture/v1/swallowed-fatal": {
        "type": "php_fatal",
        "error_type": 1,
        "error_message": "Call to a member function explode() on null",
        "error_file": FIXTURE_FILE,
        "error_line": 62,
    },
}


def _interaction(route, method, status, events):
    return {
        "type": "interaction",
        "profile": "anonymous",
        "operation": f"{method} {route}",
        "method": method,
        "route": route,
        "url": f"{SITE_URL}/wp-json{route}",
        "headers": {"Content-Type": "application/json"},
        "body": "{}",
        "response_status": status,
        "instrumentation_events": events,
    }


# The ledger the generation phase would leave behind: the deterministic fault surfaces as a 500, the
# swallowed fault as a 200 carrying a php_fatal event, and the clean route as a plain 200.
_ANONYMOUS_FIXTURE_LEDGER = [
    _interaction(
        "/qit-fuzz-fixture/v1/deterministic-fatal",
        "POST",
        500,
        [_FATALS["/qit-fuzz-fixture/v1/deterministic-fatal"]],
    ),
    _interaction(
        "/qit-fuzz-fixture/v1/swallowed-fatal",
        "POST",
        200,
        [_FATALS["/qit-fuzz-fixture/v1/swallowed-fatal"]],
    ),
    _interaction("/qit-fuzz-fixture/v1/clean", "GET", 200, []),
]
FIXTURE_LEDGER = [
    *_ANONYMOUS_FIXTURE_LEDGER,
    *[{**record, "profile": "administrator"} for record in _ANONYMOUS_FIXTURE_LEDGER],
]


def _fake_replay(record, qit, env_id, site_url, artifact_root=None):
    """Deterministically reproduce the planted faults; the clean route never reaches here."""

    route = record.get("route", "")
    fatal = _FATALS[route]
    return {
        "status": "completed",
        "reproduced": True,
        "request_attempted": True,
        "response_status": record.get("response_status", 0),
        "events": [fatal],
    }


class KnownAnswerTest(unittest.TestCase):
    @patch("qit_api_fuzz.campaign._replay", side_effect=_fake_replay)
    @patch("qit_api_fuzz.campaign._read_ledger", return_value=FIXTURE_LEDGER)
    @patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0))
    def test_fixture_produces_exactly_two_attributed_findings(
        self, run_profile, read_ledger, replay
    ):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline-routes.json"
            sut_routes = root / "sut-routes.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(json.dumps({"routes": FIXTURE_ROUTES}), encoding="utf-8")

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                SITE_URL,
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": SUT_SLUG, "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )
            on_disk = json.loads(output.read_text(encoding="utf-8"))

        # The pipeline ran to completion and the emitted contract is valid.
        self.assertEqual("completed", result["campaign"]["state"])
        self.assertEqual([], validate_result(result))
        self.assertEqual(result, on_disk)

        # Discovery and conversion saw all three fixture operations as usable targets.
        self.assertEqual(3, result["discovery"]["target_operation_count"])
        self.assertEqual(3, result["schema"]["complete"])
        self.assertEqual(0, result["schema"]["unsupported"])

        # Known answer: exactly the two planted faults become findings, both attributed to the SUT.
        findings_by_route = {finding["route"]: finding for finding in result["findings"]}
        self.assertEqual(
            {
                "/qit-fuzz-fixture/v1/deterministic-fatal",
                "/qit-fuzz-fixture/v1/swallowed-fatal",
            },
            set(findings_by_route),
        )
        self.assertTrue(all(finding["is_sut_attributed"] for finding in result["findings"]))
        self.assertNotIn("/qit-fuzz-fixture/v1/clean", findings_by_route)
        self.assertEqual([], result["anomalies"])

        # The swallowed fault is caught despite its HTTP 200, and each fault carries a distinct
        # 64-character runner fingerprint for correlation and suppression.
        swallowed = findings_by_route["/qit-fuzz-fixture/v1/swallowed-fatal"]
        self.assertEqual("php_fatal", swallowed["finding_type"])
        self.assertEqual(200, swallowed["response_status"])
        fingerprints = {finding["fingerprint"] for finding in result["findings"]}
        self.assertEqual(2, len(fingerprints))
        self.assertTrue(all(len(fingerprint) == 64 for fingerprint in fingerprints))

    @patch("qit_api_fuzz.campaign._replay", side_effect=_fake_replay)
    @patch("qit_api_fuzz.campaign._read_ledger", return_value=FIXTURE_LEDGER)
    @patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0))
    def test_clean_control_route_is_reachable_but_never_a_finding(
        self, run_profile, read_ledger, replay
    ):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline-routes.json"
            sut_routes = root / "sut-routes.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(json.dumps({"routes": FIXTURE_ROUTES}), encoding="utf-8")

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                SITE_URL,
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": SUT_SLUG, "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
            )

        # The clean route was exercised (it is in the ledger) but produced no fault, so replay was
        # only ever invoked for the two faulting candidates.
        self.assertEqual(4, replay.call_count)
        self.assertEqual([], result["anomalies"])
        self.assertNotIn(
            "/qit-fuzz-fixture/v1/clean",
            {finding["route"] for finding in result["findings"]},
        )

    @patch("qit_api_fuzz.campaign._replay", side_effect=_fake_replay)
    @patch("qit_api_fuzz.campaign._read_ledger", return_value=FIXTURE_LEDGER)
    @patch("qit_api_fuzz.campaign._run_profile", return_value=("completed", 0))
    def test_exact_fingerprint_can_suppress_one_known_answer_without_hiding_the_other(
        self, run_profile, read_ledger, replay
    ):
        suppressed_route = "/qit-fuzz-fixture/v1/deterministic-fatal"
        fingerprint = finding_fingerprint(
            {
                "finding_type": "php_fatal",
                "method": "POST",
                "route": suppressed_route,
                "error_type": 1,
                "error_file": FIXTURE_FILE,
                "error_line": 44,
            }
        )
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            baseline = root / "baseline-routes.json"
            sut_routes = root / "sut-routes.json"
            suppressions = root / "suppressions.json"
            output = root / "result.json"
            baseline.write_text(json.dumps({"routes": {}}), encoding="utf-8")
            sut_routes.write_text(json.dumps({"routes": FIXTURE_ROUTES}), encoding="utf-8")
            suppressions.write_text(
                json.dumps(
                    [
                        {
                            "fingerprint": fingerprint,
                            "reason": "Known-answer suppression test",
                            "owner": "qit",
                            "expires_at": "2099-12-31",
                        }
                    ]
                ),
                encoding="utf-8",
            )

            result = run_campaign(
                baseline,
                sut_routes,
                output,
                root / "artifacts",
                SITE_URL,
                "/path/to/qit",
                "qitenv123",
                {"woo_id": 123, "slug": SUT_SLUG, "version": "1.0.0"},
                {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
                suppressions,
            )

        findings_by_route = {finding["route"]: finding for finding in result["findings"]}
        self.assertTrue(findings_by_route[suppressed_route]["suppressed"])
        self.assertFalse(
            findings_by_route["/qit-fuzz-fixture/v1/swallowed-fatal"]["suppressed"]
        )
        self.assertEqual([fingerprint], [item["fingerprint"] for item in result["suppressions"]])


if __name__ == "__main__":
    unittest.main()
