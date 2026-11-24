import hashlib
import io
import json
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path

from qit_api_fuzz.runner import (
    KNOWN_ANSWER_SUT_SLUG,
    _environment_json,
    _local_plugin_source,
    _plugin_slug,
    _plugins,
    _qit_provenance,
    _write_unavailable,
)


class RunnerTest(unittest.TestCase):
    def test_parses_qit_json_after_diagnostic_output(self):
        output = 'Preparing environment\n{"env_id":"qitenv123","site_url":"http://qit.test"}\n'
        self.assertEqual("qitenv123", _environment_json(output)["env_id"])

    def test_parses_github_actions_quoted_plugin_json(self):
        plugins = _plugins("'[{\"slug\":\"woocommerce-subscriptions\"}]'")
        self.assertEqual("woocommerce-subscriptions", plugins[0]["slug"])

    def test_normalizes_the_wporg_woocommerce_download_slug(self):
        self.assertEqual("woocommerce", _plugin_slug("wporg-woocommerce"))

    def test_known_answer_sut_resolves_to_the_repository_fixture(self):
        root = Path("/repo")
        test_root = root / "ci" / "tests" / "api-fuzz"

        source = _local_plugin_source(root, test_root, KNOWN_ANSWER_SUT_SLUG, "ignored")

        self.assertEqual(
            test_root / "test-package" / "fixtures" / KNOWN_ANSWER_SUT_SLUG,
            source,
        )

    def test_records_qit_source_and_binary_checksum(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            qit = root / "qit"
            metadata = root / "qit-source.json"
            qit.write_bytes(b"api-fuzz-qit")
            metadata.write_text(
                json.dumps(
                    {
                        "repository": "woocommerce/qit-cli",
                        "pull_request": 473,
                        "commit": "4f83f2d54810f9053d76fbcd67086653f27ba5d2",
                    }
                ),
                encoding="utf-8",
            )

            provenance = _qit_provenance(qit, metadata)

        self.assertEqual("woocommerce/qit-cli", provenance["repository"])
        self.assertEqual(473, provenance["pull_request"])
        self.assertEqual(hashlib.sha256(b"api-fuzz-qit").hexdigest(), provenance["sha256"])

    def test_repository_qit_uses_the_declared_dev_build_sentinel(self):
        test_root = Path(__file__).resolve().parents[1]
        qit = test_root / "qit"
        metadata = json.loads((test_root / "qit-source.json").read_text(encoding="utf-8"))

        self.assertEqual("qit_dev_build", metadata["build_version"])
        self.assertIn(
            b"App::setVar('CLI_VERSION', 'qit_dev_build');",
            qit.read_bytes(),
        )

    def test_rejects_a_qit_binary_that_does_not_match_declared_provenance(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            qit = root / "qit"
            metadata = root / "qit-source.json"
            qit.write_bytes(b"unexpected-binary")
            metadata.write_text(json.dumps({"sha256": "0" * 64}), encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "checksum does not match"):
                _qit_provenance(qit, metadata)

    def test_unavailable_result_is_also_visible_in_ci_output(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "api-fuzz-results.json"
            stderr = io.StringIO()

            with redirect_stderr(stderr):
                _write_unavailable(output, {"slug": "example"}, {}, RuntimeError("env:up failed"))

            result = json.loads(output.read_text(encoding="utf-8"))

        self.assertEqual("unavailable", result["campaign"]["state"])
        self.assertIn("API fuzz runner unavailable: RuntimeError: env:up failed", stderr.getvalue())
        self.assertIn(str(output), stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
