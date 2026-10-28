import unittest
from datetime import date

from qit_api_fuzz.result import apply_suppressions, empty_result, finding_fingerprint, validate_result


class ResultContractTest(unittest.TestCase):
    def setUp(self):
        self.result = empty_result(
            {"woo_id": 123, "slug": "woocommerce-subscriptions", "version": "7.0.0"},
            {"wordpress": "6.8", "woocommerce": "10.0", "php": "8.3"},
        )

    def test_empty_completed_campaign_satisfies_contract(self):
        self.result["campaign"]["state"] = "completed"
        self.assertEqual([], validate_result(self.result))

    def test_contract_requires_sut_owned_discovery_scope(self):
        self.result["discovery"]["scope"] = "all_routes"

        self.assertIn("discovery.scope must be sut_owned", validate_result(self.result))

    def test_contract_requires_explicit_campaign_state_and_private_14_day_artifacts(self):
        self.result["campaign"]["state"] = "clean"
        self.result["artifacts"]["private"] = False
        self.result["artifacts"]["retention_days"] = 30

        errors = validate_result(self.result)

        self.assertIn("campaign.state is invalid", errors)
        self.assertIn("artifacts.private must be true", errors)
        self.assertIn("artifacts.retention_days must be 14", errors)

    def test_finding_requires_two_clean_state_replays(self):
        finding = {
            "fingerprint": "abc",
            "finding_type": "sut_5xx",
            "method": "POST",
            "route": "/wc/v3/subscriptions",
            "confirmation": {"clean_state_replays": 1, "reproduced": 1},
        }
        self.result["findings"] = [finding]

        self.assertIn(
            "findings[0].confirmation must record two clean-state replays",
            validate_result(self.result),
        )

    def test_suppression_is_exact_auditable_and_expires(self):
        finding = {
            "finding_type": "sut_5xx",
            "method": "post",
            "route": "/wc/v3/subscriptions",
            "error_type": "TypeError",
            "error_file": "/sut/includes/controller.php",
            "error_line": 123,
        }
        fingerprint = finding_fingerprint(finding)
        applied = apply_suppressions(
            [finding],
            [
                {
                    "fingerprint": fingerprint,
                    "reason": "Known upstream fixture mismatch",
                    "owner": "qit-team",
                    "expires_at": "2026-08-01",
                },
                {
                    "fingerprint": "expired",
                    "reason": "Old exception",
                    "owner": "qit-team",
                    "expires_at": "2026-01-01",
                },
            ],
            today=date(2026, 7, 17),
        )

        self.assertTrue(finding["suppressed"])
        self.assertEqual("qit-team", finding["suppression"]["owner"])
        self.assertEqual([fingerprint], [item["fingerprint"] for item in applied])


if __name__ == "__main__":
    unittest.main()
