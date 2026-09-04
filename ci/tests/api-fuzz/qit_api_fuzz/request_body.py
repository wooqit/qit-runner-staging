"""Capture exact request bodies without bloating the compact campaign result."""

from __future__ import annotations

import base64
import hashlib
from pathlib import Path
from typing import Any, Dict, Mapping, Optional


INLINE_BODY_LIMIT = 65536


class RequestBodyArtifactError(RuntimeError):
    """An exact request body could not be loaded safely for confirmation."""

    def __init__(self, reason: str):
        self.reason = reason
        super().__init__(reason)


def capture_request_body(body: Any, artifact_root: Path) -> Dict[str, Any]:
    """Keep small bodies inline and persist larger bodies as content-addressed artifacts."""

    if body is None:
        return {}

    text: Optional[str]
    if isinstance(body, str):
        text = body
        data = body.encode("utf-8")
    elif isinstance(body, bytes):
        data = body
        try:
            text = body.decode("utf-8")
        except UnicodeDecodeError:
            text = None
    else:
        text = str(body)
        data = text.encode("utf-8")

    if len(data) <= INLINE_BODY_LIMIT:
        if text is not None:
            return {"body": text}
        return {"body_base64": base64.b64encode(data).decode("ascii")}

    digest = hashlib.sha256(data).hexdigest()
    relative_path = Path("request-bodies") / f"{digest}.bin"
    path = artifact_root / relative_path
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.exists():
        path.write_bytes(data)

    return {
        "body_file": relative_path.as_posix(),
        "body_size": len(data),
        "body_sha256": digest,
    }


def load_request_body(record: Mapping[str, Any], artifact_root: Optional[Path] = None) -> Optional[bytes]:
    """Load and verify the exact body represented by an interaction ledger record."""

    if isinstance(record.get("body_base64"), str):
        try:
            return base64.b64decode(record["body_base64"])
        except ValueError:
            return None
    if isinstance(record.get("body"), str):
        return record["body"].encode("utf-8")
    if not isinstance(record.get("body_file"), str):
        return None
    if artifact_root is None:
        raise RequestBodyArtifactError("request_body_artifact_root_missing")

    digest = str(record.get("body_sha256", ""))
    relative_path = Path(record["body_file"])
    expected_path = Path("request-bodies") / f"{digest}.bin"
    if (
        len(digest) != 64
        or any(character not in "0123456789abcdef" for character in digest)
        or relative_path != expected_path
    ):
        raise RequestBodyArtifactError("request_body_artifact_invalid")

    try:
        data = (artifact_root / relative_path).read_bytes()
    except FileNotFoundError as error:
        raise RequestBodyArtifactError("request_body_artifact_missing") from error
    except OSError as error:
        raise RequestBodyArtifactError("request_body_artifact_unreadable") from error

    if hashlib.sha256(data).hexdigest() != digest:
        raise RequestBodyArtifactError("request_body_artifact_hash_mismatch")
    if isinstance(record.get("body_size"), int) and len(data) != record["body_size"]:
        raise RequestBodyArtifactError("request_body_artifact_size_mismatch")
    return data
