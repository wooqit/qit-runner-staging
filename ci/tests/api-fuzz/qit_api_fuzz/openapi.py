"""Convert WordPress REST endpoint metadata into an OpenAPI 3.0.3 document."""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Mapping, Sequence, Tuple

from .routes import RouteOperation


NAMED_GROUP = re.compile(r"\(\?P?<(?P<name>[A-Za-z_][A-Za-z0-9_]*)>(?P<pattern>(?:\\.|[^)])+)\)")
REGEX_TOKEN = re.compile(r"(?<!\\)[\[\]()?+*|^$]")
SCHEMA_KEYS = {
    "type",
    "format",
    "enum",
    "default",
    "minimum",
    "maximum",
    "minLength",
    "maxLength",
    "minItems",
    "maxItems",
    "uniqueItems",
    "pattern",
    "items",
    "properties",
    "additionalProperties",
    "nullable",
    "description",
}


@dataclass(frozen=True)
class ConversionResult:
    document: Dict[str, Any]
    operation_reports: Tuple[Dict[str, Any], ...]

    @property
    def usable_operation_count(self) -> int:
        return sum(report["usability"] != "unsupported" for report in self.operation_reports)


def _path_template(route: str) -> Tuple[str, Dict[str, str], List[str]]:
    patterns: Dict[str, str] = {}

    def replace(match: re.Match[str]) -> str:
        name = match.group("name")
        patterns[name] = match.group("pattern")
        return "{" + name + "}"

    path = NAMED_GROUP.sub(replace, route).replace("\\/", "/")
    path = path.lstrip("^").rstrip("$")
    lost: List[str] = []
    if REGEX_TOKEN.search(path):
        lost.append("route_contains_unsupported_regex")
    return path, patterns, lost


def _normalize_type(value: Any) -> Tuple[str, bool]:
    if isinstance(value, Sequence) and not isinstance(value, (str, bytes)):
        values = [str(item) for item in value if item != "null"]
        return (values[0] if values else "string", len(values) > 1 or "null" in value)

    raw = str(value or "").lower()
    aliases = {
        "int": "integer",
        "bool": "boolean",
        "float": "number",
        "numeric": "number",
        "date-time": "string",
        "datetime": "string",
    }
    return aliases.get(raw, raw or "string"), False


def _schema(source: Any, lost: List[str], location: str) -> Dict[str, Any]:
    if not isinstance(source, Mapping):
        lost.append(f"{location}:missing_schema")
        return {"type": "string"}

    candidate = source.get("schema") if isinstance(source.get("schema"), Mapping) else source
    if isinstance(candidate.get("type"), Mapping):
        # Some extension callbacks put a complete argument schema under `type` instead of making
        # `type` a scalar. Preserve the nested constraints while emitting valid OpenAPI.
        nested_type = candidate["type"]
        candidate = {**candidate, **nested_type}
        lost.append(f"{location}:nested_schema_in_type")
    data = {key: candidate[key] for key in SCHEMA_KEYS if key in candidate}
    if isinstance(data.get("enum"), Mapping):
        # WordPress accepts associative enum maps; OpenAPI requires an array of allowed values.
        data["enum"] = list(data["enum"].values())
        lost.append(f"{location}:associative_enum_normalized")
    raw_type = data.get("type")
    normalized_type, nullable = _normalize_type(raw_type)
    data["type"] = normalized_type
    if nullable or (isinstance(raw_type, Sequence) and "null" in raw_type):
        data["nullable"] = True

    if raw_type in ("date-time", "datetime") and "format" not in data:
        data["format"] = "date-time"

    if normalized_type == "array":
        data["items"] = _schema(data.get("items", {}), lost, f"{location}.items")
    elif normalized_type == "object":
        properties = data.get("properties", {})
        if isinstance(properties, Mapping):
            data["properties"] = {
                str(name): _schema(value, lost, f"{location}.{name}")
                for name, value in properties.items()
            }
        else:
            data.pop("properties", None)
            lost.append(f"{location}:invalid_properties")

    unsupported = set(candidate) - SCHEMA_KEYS - {
        "required",
        "validate_callback",
        "sanitize_callback",
        "context",
        "readonly",
        "arg_options",
        "schema",
    }
    for key in sorted(unsupported):
        lost.append(f"{location}:unsupported_{key}")
    if "validate_callback" in candidate:
        lost.append(f"{location}:custom_validation_callback")
    if "sanitize_callback" in candidate:
        lost.append(f"{location}:custom_sanitization_callback")
    return data


def _operation_to_openapi(operation: RouteOperation) -> Tuple[str, Dict[str, Any], Dict[str, Any]]:
    path, captures, lost = _path_template(operation.route)
    parameters: List[Dict[str, Any]] = []
    body_properties: Dict[str, Any] = {}
    body_required: List[str] = []
    typed_args = 0

    for name, metadata in operation.args.items():
        metadata = metadata if isinstance(metadata, Mapping) else {}
        if metadata.get("readonly"):
            continue
        arg_schema = _schema(metadata, lost, f"arg.{name}")
        if metadata.get("type") or metadata.get("schema"):
            typed_args += 1
        required = bool(metadata.get("required"))

        if name in captures:
            arg_schema.setdefault("pattern", captures[name])
            parameters.append({"name": name, "in": "path", "required": True, "schema": arg_schema})
        elif operation.method in {"GET", "DELETE"}:
            parameter: Dict[str, Any] = {
                "name": name,
                "in": "query",
                "required": required,
                "schema": arg_schema,
            }
            if "description" in metadata:
                parameter["description"] = metadata["description"]
            parameters.append(parameter)
        else:
            body_properties[name] = arg_schema
            if required:
                body_required.append(name)

    for name, pattern in captures.items():
        if not any(parameter["name"] == name and parameter["in"] == "path" for parameter in parameters):
            parameters.append(
                {
                    "name": name,
                    "in": "path",
                    "required": True,
                    "schema": {"type": "string", "pattern": pattern},
                    "description": "Synthesized from the WordPress route regex.",
                }
            )
            lost.append(f"path.{name}:missing_wordpress_arg_metadata")

    spec: Dict[str, Any] = {
        "operationId": operation.fingerprint[:16],
        "responses": {
            "default": {
                "description": "Response generated by the WordPress REST endpoint",
            }
        },
        "x-qit-route-regex": operation.route,
        "x-qit-operation-key": operation.key,
    }
    if parameters:
        spec["parameters"] = sorted(parameters, key=lambda item: (item["in"], item["name"]))
    if body_properties:
        request_schema: Dict[str, Any] = {"type": "object", "properties": body_properties}
        if body_required:
            request_schema["required"] = sorted(body_required)
        spec["requestBody"] = {
            "required": bool(body_required),
            "content": {"application/json": {"schema": request_schema}},
        }

    unsupported = "route_contains_unsupported_regex" in lost
    if unsupported:
        usability = "unsupported"
    elif not operation.args:
        usability = "untyped"
    elif typed_args == len(operation.args) and not lost:
        usability = "complete"
    else:
        usability = "partial"

    report = {
        "operation": operation.key,
        "openapi_path": path,
        "usability": usability,
        "argument_count": len(operation.args),
        "typed_argument_count": typed_args,
        "lost_constraints": sorted(set(lost)),
    }
    return path, spec, report


def build_openapi_document(
    operations: Iterable[RouteOperation],
    base_url: str,
    title: str = "QIT SUT REST API",
) -> ConversionResult:
    """Build the campaign schema and a per-operation schema-usability report."""

    converted: List[Tuple[RouteOperation, str, Dict[str, Any], Dict[str, Any]]] = []
    path_methods: Dict[Tuple[str, str], List[int]] = {}
    for operation in sorted(operations, key=lambda item: item.key):
        path, spec, report = _operation_to_openapi(operation)
        index = len(converted)
        converted.append((operation, path, spec, report))
        path_methods.setdefault((path, operation.method.lower()), []).append(index)

    for indices in path_methods.values():
        if len(indices) < 2:
            continue
        collision_operations = sorted(converted[index][0].key for index in indices)
        for index in indices:
            report = converted[index][3]
            report["usability"] = "unsupported"
            report["lost_constraints"] = sorted(
                set([*report["lost_constraints"], "openapi_path_method_collision"])
            )
            report["collision_operations"] = collision_operations

    paths: Dict[str, Any] = {}
    reports: List[Dict[str, Any]] = []
    for operation, path, spec, report in converted:
        reports.append(report)
        if report["usability"] == "unsupported":
            continue
        paths.setdefault(path, {})[operation.method.lower()] = spec

    document = {
        "openapi": "3.0.3",
        "info": {"title": title, "version": "1.0.0"},
        "servers": [{"url": base_url.rstrip("/")}],
        "paths": paths,
    }
    return ConversionResult(document, tuple(reports))
