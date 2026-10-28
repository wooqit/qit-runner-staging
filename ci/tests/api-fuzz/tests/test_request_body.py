import tempfile
import unittest
from pathlib import Path

from qit_api_fuzz.request_body import INLINE_BODY_LIMIT, capture_request_body, load_request_body


class RequestBodyTest(unittest.TestCase):
    def test_large_utf8_body_is_preserved_as_an_exact_artifact(self):
        body = "é" * (INLINE_BODY_LIMIT + 1)
        with tempfile.TemporaryDirectory() as directory:
            artifact_root = Path(directory)
            record = capture_request_body(body, artifact_root)

            self.assertNotIn("body", record)
            self.assertEqual(len(body.encode("utf-8")), record["body_size"])
            self.assertEqual(body.encode("utf-8"), load_request_body(record, artifact_root))
            self.assertTrue((artifact_root / record["body_file"]).is_file())

    def test_large_binary_body_is_preserved_as_an_exact_artifact(self):
        body = b"\xff" * (INLINE_BODY_LIMIT + 1)
        with tempfile.TemporaryDirectory() as directory:
            artifact_root = Path(directory)
            record = capture_request_body(body, artifact_root)

            self.assertNotIn("body_base64", record)
            self.assertEqual(len(body), record["body_size"])
            self.assertEqual(body, load_request_body(record, artifact_root))
            self.assertTrue((artifact_root / record["body_file"]).is_file())


if __name__ == "__main__":
    unittest.main()
