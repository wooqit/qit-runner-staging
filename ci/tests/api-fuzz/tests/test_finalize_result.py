import json
import tempfile
import unittest
from pathlib import Path

from qit_api_fuzz.finalize_result import finalize_result
from qit_api_fuzz.result import empty_result, validate_result


class FinalizeResultTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.result_path = self.root / "api-fuzz-results.json"
        self.failure_path = self.root / "download-failure.json"
        self.sut = {"woo_id": 123456, "slug": "my-plugin", "version": "1.2.3"}
        self.environment = {"wordpress": "7.0", "woocommerce": "10.9", "php": "8.3"}

    def tearDown(self):
        self.directory.cleanup()

    def test_writes_valid_unavailable_result_for_download_failure(self):
        self.failure_path.write_text(
            json.dumps(
                {
                    "stage": "plugin_download",
                    "code": "download_transport_error",
                    "message": "Could not download my-plugin: artifact request failed.\n",
                }
            ),
            encoding="utf-8",
        )

        self.assertTrue(
            finalize_result(self.result_path, self.failure_path, self.sut, self.environment)
        )
        result = json.loads(self.result_path.read_text(encoding="utf-8"))

        self.assertEqual([], validate_result(result))
        self.assertEqual("unavailable", result["campaign"]["state"])
        self.assertEqual("environment_setup_failed", result["campaign"]["stop_reason"])
        self.assertEqual("download_transport_error", result["errors"][0]["type"])
        self.assertNotIn("\n", result["errors"][0]["message"])

    def test_does_not_overwrite_campaign_result(self):
        expected = empty_result(self.sut, self.environment)
        expected["campaign"]["state"] = "completed"
        self.result_path.write_text(json.dumps(expected) + "\n", encoding="utf-8")

        self.assertFalse(
            finalize_result(self.result_path, self.failure_path, self.sut, self.environment)
        )
        self.assertEqual(expected, json.loads(self.result_path.read_text(encoding="utf-8")))

    def test_replaces_invalid_campaign_result_with_unavailable_result(self):
        invalid_results = {
            "empty": "",
            "malformed": '{"campaign":',
            "incomplete": '{"campaign":{"state":"completed"}}',
        }

        for name, contents in invalid_results.items():
            with self.subTest(name=name):
                self.result_path.write_text(contents, encoding="utf-8")

                self.assertTrue(
                    finalize_result(self.result_path, self.failure_path, self.sut, self.environment)
                )
                result = json.loads(self.result_path.read_text(encoding="utf-8"))

                self.assertEqual([], validate_result(result))
                self.assertEqual("unavailable", result["campaign"]["state"])
                self.assertEqual("workflow_setup_failed", result["errors"][0]["type"])

    def test_cancellation_has_no_campaign_state(self):
        self.result_path.write_text('{"campaign":{"state":"unavailable"}}\n', encoding="utf-8")

        self.assertTrue(
            finalize_result(
                self.result_path,
                self.failure_path,
                self.sut,
                self.environment,
                cancelled=True,
            )
        )
        self.assertEqual({}, json.loads(self.result_path.read_text(encoding="utf-8")))


if __name__ == "__main__":
    unittest.main()
