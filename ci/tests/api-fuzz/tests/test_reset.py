import json
import subprocess
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from qit_api_fuzz.reset import RESET_PHASES, run_reset


def reset_payload(status="success", failed_phase=None):
    phases = {
        name: {"status": "completed", "seconds": 0.01}
        for name in RESET_PHASES
    }
    phases["snapshot_copy"] = {"status": "skipped", "seconds": 0.0}
    phases["temporary_file_cleanup"] = {"status": "skipped", "seconds": 0.0}
    if failed_phase:
        phases[failed_phase] = {"status": "failed", "seconds": 0.5}
    return {
        "status": status,
        "env_id": "qitenv123",
        "strategy": "container_staged",
        "total_seconds": 1.2,
        "failed_phase": failed_phase,
        "message": "import failed" if failed_phase else "",
        "phases": phases,
    }


class ResetProtocolTest(unittest.TestCase):
    @patch("qit_api_fuzz.reset.time.monotonic", side_effect=[10.0, 11.5])
    @patch("qit_api_fuzz.reset.subprocess.run")
    def test_parses_success_and_measures_caller_overhead(self, run, monotonic):
        run.return_value = SimpleNamespace(
            returncode=0,
            stdout="QIT diagnostic\n" + json.dumps(reset_payload()),
            stderr="",
        )

        observation = run_reset("/path/to/qit", "qitenv123")

        self.assertEqual("completed", observation["status"])
        self.assertEqual(1.5, observation["seconds"])
        self.assertEqual(1.2, observation["cli_seconds"])
        self.assertEqual(0.3, observation["caller_overhead_seconds"])
        self.assertEqual("container_staged", observation["strategy"])
        self.assertEqual("completed", observation["phases"]["database_import"]["status"])
        self.assertEqual(
            ["/path/to/qit", "env:reset", "qitenv123", "--json", "--no-interaction"],
            run.call_args.args[0],
        )

    @patch("qit_api_fuzz.reset.subprocess.run")
    def test_maps_failed_phase_to_specific_infrastructure_reason(self, run):
        run.return_value = SimpleNamespace(
            returncode=1,
            stdout=json.dumps(reset_payload("failed", "database_import")),
            stderr="",
        )

        observation = run_reset("qit", "qitenv123")

        self.assertEqual("infrastructure_error", observation["status"])
        self.assertEqual("clean_state_database_import_failed", observation["reason"])
        self.assertEqual("database_import", observation["failed_phase"])

    @patch("qit_api_fuzz.reset.subprocess.run")
    def test_rejects_missing_phase_timing(self, run):
        payload = reset_payload()
        del payload["phases"]["object_cache_flush"]
        run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(payload), stderr="")

        observation = run_reset("qit", "qitenv123")

        self.assertEqual("infrastructure_error", observation["status"])
        self.assertEqual("clean_state_restore_protocol_error", observation["reason"])

    @patch("qit_api_fuzz.reset.subprocess.run")
    def test_rejects_malformed_json(self, run):
        run.return_value = SimpleNamespace(returncode=1, stdout="not-json", stderr="bad output")

        observation = run_reset("qit", "qitenv123")

        self.assertEqual("clean_state_restore_protocol_error", observation["reason"])
        self.assertEqual("bad output", observation["message"])

    @patch(
        "qit_api_fuzz.reset.subprocess.run",
        side_effect=subprocess.TimeoutExpired(cmd=["qit", "env:reset"], timeout=45),
    )
    def test_timeout_is_an_infrastructure_attempt(self, run):
        observation = run_reset("qit", "qitenv123")

        self.assertEqual("infrastructure_error", observation["status"])
        self.assertEqual("clean_state_restore_timeout", observation["reason"])
        self.assertTrue(observation["reset_performed"])
        self.assertEqual(0.0, observation["caller_overhead_seconds"])

    @patch("qit_api_fuzz.reset.time.monotonic", side_effect=[10.0, 10.25])
    @patch("qit_api_fuzz.reset.subprocess.run", side_effect=OSError("qit not found"))
    def test_exec_error_does_not_claim_reset_was_performed(self, run, monotonic):
        observation = run_reset("qit", "qitenv123")

        self.assertEqual("infrastructure_error", observation["status"])
        self.assertEqual("clean_state_restore_exec_error", observation["reason"])
        self.assertFalse(observation["reset_performed"])
        self.assertEqual(0.25, observation["seconds"])
        self.assertEqual(0.0, observation["caller_overhead_seconds"])
