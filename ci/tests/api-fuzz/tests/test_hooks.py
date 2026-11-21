import json
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

try:
    import qit_api_fuzz.hooks as hooks
except ImportError:  # The hooks module needs schemathesis; the rest of the suite does not.
    hooks = None


@unittest.skipIf(hooks is None, "schemathesis is not installed")
class ResetCadenceTest(unittest.TestCase):
    def setUp(self):
        hooks._LAST_OPERATION["key"] = None

    def test_reset_every_defaults_to_one_and_ignores_bad_values(self):
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("QIT_API_FUZZ_RESET_EVERY", None)
            self.assertEqual(1, hooks._reset_every())
        with patch.dict(os.environ, {"QIT_API_FUZZ_RESET_EVERY": "not-a-number"}):
            self.assertEqual(1, hooks._reset_every())
        with patch.dict(os.environ, {"QIT_API_FUZZ_RESET_EVERY": "0"}):
            self.assertEqual(1, hooks._reset_every())
        with patch.dict(os.environ, {"QIT_API_FUZZ_RESET_EVERY": "5"}):
            self.assertEqual(5, hooks._reset_every())

    def test_operation_batch_is_the_default_campaign_strategy(self):
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("QIT_API_FUZZ_RESET_MODE", None)
            os.environ.pop("QIT_API_FUZZ_RESET_EVERY", None)
            self.assertEqual("operation", hooks._reset_mode())
        with patch.dict(os.environ, {"QIT_API_FUZZ_RESET_MODE": "request"}):
            self.assertEqual("request", hooks._reset_mode())

    @patch.dict(
        os.environ,
        {"QIT_API_FUZZ_RESET_EVERY": "3", "QIT_API_FUZZ_REQUEST_BUDGET": "2500"},
    )
    @patch("qit_api_fuzz.hooks._increment_count", return_value=1)
    @patch("qit_api_fuzz.hooks._reset_environment")
    @patch("qit_api_fuzz.hooks._read_count")
    def test_reset_only_at_the_start_of_each_cadence_window(self, read_count, reset_env, increment):
        # count 0 opens the first window -> reset; count 1 and 2 reuse it -> no reset.
        for count, should_reset in ((0, True), (1, False), (2, False), (3, True)):
            reset_env.reset_mock()
            read_count.return_value = count
            case = SimpleNamespace(headers=None)
            hooks.before_call(None, case, {})
            self.assertEqual(should_reset, reset_env.called, f"count={count}")
            self.assertEqual(should_reset, hooks._LAST_RESET["performed"], f"count={count}")

    @patch.dict(
        os.environ,
        {"QIT_API_FUZZ_RESET_MODE": "operation", "QIT_API_FUZZ_REQUEST_BUDGET": "2500"},
    )
    @patch("qit_api_fuzz.hooks._increment_count", return_value=1)
    @patch("qit_api_fuzz.hooks._reset_environment")
    @patch("qit_api_fuzz.hooks._read_count", return_value=0)
    def test_reset_when_the_generated_operation_changes(
        self, read_count, reset_env, increment
    ):
        def case(operation):
            method, path = operation.split(" ", 1)
            return SimpleNamespace(
                headers=None,
                operation=SimpleNamespace(
                    definition=SimpleNamespace(raw={"x-qit-operation-key": operation}),
                    method=method,
                    path=path,
                ),
            )

        for operation, should_reset in (
            ("GET /one", True),
            ("GET /one", False),
            ("POST /two", True),
            ("POST /two", False),
            ("GET /one", True),
        ):
            reset_env.reset_mock()
            hooks.before_call(None, case(operation), {})
            self.assertEqual(should_reset, reset_env.called, operation)
            self.assertEqual(should_reset, hooks._LAST_RESET["performed"], operation)

    @patch.dict(os.environ, {"QIT_API_FUZZ_REQUEST_BUDGET": "2500"})
    @patch("qit_api_fuzz.hooks._increment_count", return_value=1)
    @patch("qit_api_fuzz.hooks._reset_environment")
    @patch("qit_api_fuzz.hooks._read_count", return_value=0)
    def test_reset_duration_is_handed_to_after_call(self, read_count, reset_env, increment):
        case = SimpleNamespace(headers=None)
        hooks.before_call(None, case, {})
        self.assertTrue(hooks._LAST_RESET["performed"])
        self.assertGreaterEqual(hooks._LAST_RESET["seconds"], 0.0)

    @patch("qit_api_fuzz.hooks._increment_count")
    @patch("qit_api_fuzz.hooks._reset_environment")
    @patch("qit_api_fuzz.hooks._read_count", return_value=0)
    def test_failed_reset_attempt_is_recorded_before_generation_stops(
        self, read_count, reset_env, increment
    ):
        reset_env.return_value = {
            "status": "infrastructure_error",
            "reason": "clean_state_database_import_failed",
            "reset_performed": True,
            "seconds": 1.2,
            "cli_seconds": 1.1,
            "caller_overhead_seconds": 0.1,
            "strategy": "container_staged",
            "failed_phase": "database_import",
            "message": "import failed",
            "phases": {},
        }
        case = SimpleNamespace(
            headers=None,
            operation=SimpleNamespace(
                definition=SimpleNamespace(raw={"x-qit-operation-key": "POST /items"}),
                method="POST",
                path="/items",
            ),
        )

        with tempfile.TemporaryDirectory() as directory, patch.dict(
            os.environ,
            {
                "QIT_API_FUZZ_LEDGER": str(Path(directory) / "interactions.jsonl"),
                "QIT_API_FUZZ_REQUEST_BUDGET": "2500",
            },
        ):
            with self.assertRaisesRegex(RuntimeError, "clean database snapshot"):
                hooks.before_call(None, case, {})
            records = [
                json.loads(line)
                for line in (Path(directory) / "interactions.jsonl").read_text().splitlines()
            ]

        self.assertEqual(["reset", "isolation_error"], [record["type"] for record in records])
        self.assertEqual("database_import", records[0]["failed_phase"])
        self.assertEqual("clean_state_database_import_failed", records[1]["reason"])
        increment.assert_not_called()


@unittest.skipIf(hooks is None, "schemathesis is not installed")
class ResponseErrorCodeTest(unittest.TestCase):
    def test_extracts_wordpress_error_code_from_json_response(self):
        response = SimpleNamespace(
            json=lambda: {"code": "woocommerce_rest_trash_not_supported"}
        )

        self.assertEqual(
            "woocommerce_rest_trash_not_supported",
            hooks._response_error_code(response),
        )

    def test_returns_empty_code_for_non_json_or_non_object_response(self):
        non_json = SimpleNamespace(json=lambda: (_ for _ in ()).throw(ValueError("invalid")))
        array_json = SimpleNamespace(json=lambda: [{"code": "not-at-the-top-level"}])

        self.assertEqual("", hooks._response_error_code(non_json))
        self.assertEqual("", hooks._response_error_code(array_json))


if __name__ == "__main__":
    unittest.main()
