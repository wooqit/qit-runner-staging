"""Normalize WordPress REST route exports and identify SUT-owned changes."""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, Tuple


METHOD_ALIASES = {
    "READABLE": ("GET",),
    "CREATABLE": ("POST",),
    "EDITABLE": ("PUT", "PATCH"),
    "DELETABLE": ("DELETE",),
    "ALLMETHODS": ("GET", "POST", "PUT", "PATCH", "DELETE"),
}
IGNORED_METHODS = {"HEAD", "OPTIONS"}


def _canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True)


def _fingerprint(value: Any) -> str:
    return hashlib.sha256(_canonical_json(value).encode("utf-8")).hexdigest()


def _normalize_methods(methods: Any) -> Tuple[str, ...]:
    if isinstance(methods, Mapping):
        values: Iterable[Any] = (name for name, enabled in methods.items() if enabled)
    elif isinstance(methods, str):
        values = methods.replace("|", ",").split(",")
    elif isinstance(methods, Sequence):
        values = methods
    else:
        values = ()

    normalized: List[str] = []
    for value in values:
        method = str(value).strip().upper()
        expanded = METHOD_ALIASES.get(method, (method,))
        for candidate in expanded:
            if candidate and candidate not in IGNORED_METHODS and candidate not in normalized:
                normalized.append(candidate)
    return tuple(sorted(normalized))


def _normalize_mapping(value: Any) -> Dict[str, Any]:
    if not isinstance(value, Mapping):
        return {}
    return {str(key): value[key] for key in sorted(value, key=str)}


def _normalize_callback(value: Any) -> Dict[str, Any]:
    if isinstance(value, str):
        return {"name": value}
    if not isinstance(value, Mapping):
        return {}

    allowed = (
        "type",
        "class",
        "declaring_class",
        "method",
        "function",
        "name",
        "file",
        "line",
    )
    return {key: value[key] for key in allowed if key in value and value[key] not in (None, "")}


@dataclass(frozen=True)
class RouteOperation:
    """One normalized HTTP operation exported from the WordPress REST server."""

    method: str
    route: str
    args: Dict[str, Any] = field(default_factory=dict)
    schema: Dict[str, Any] = field(default_factory=dict)
    callback: Dict[str, Any] = field(default_factory=dict)
    permission_callback: Dict[str, Any] = field(default_factory=dict)
    registration_source: Dict[str, Any] = field(default_factory=dict)
    fingerprint: str = ""

    @property
    def key(self) -> str:
        return f"{self.method} {self.route}"

    @classmethod
    def create(cls, method: str, route: str, handler: Mapping[str, Any]) -> "RouteOperation":
        args = _normalize_mapping(handler.get("args"))
        schema = _normalize_mapping(handler.get("schema"))
        callback = _normalize_callback(handler.get("callback"))
        permission_callback = _normalize_callback(handler.get("permission_callback"))
        registration_source = _normalize_callback(handler.get("registration_source"))
        material = {
            "method": method,
            "route": route,
            "args": args,
            "schema": schema,
            "callback": callback,
            "permission_callback": permission_callback,
            "registration_source": registration_source,
        }
        return cls(
            method=method,
            route=route,
            args=args,
            schema=schema,
            callback=callback,
            permission_callback=permission_callback,
            registration_source=registration_source,
            fingerprint=_fingerprint(material),
        )


@dataclass(frozen=True)
class RouteChange:
    """An operation added or modified after the SUT was activated."""

    change: str
    operation: RouteOperation
    baseline_fingerprint: Optional[str] = None
    changed_fields: Tuple[str, ...] = ()

    def as_dict(self) -> Dict[str, Any]:
        return {
            "change": self.change,
            "operation": self.operation.key,
            "fingerprint": self.operation.fingerprint,
            "baseline_fingerprint": self.baseline_fingerprint,
            "changed_fields": list(self.changed_fields),
            "callback": self.operation.callback,
            "registration_source": self.operation.registration_source,
        }


@dataclass(frozen=True)
class RouteDiff:
    """Discovery result used as the ownership boundary for a campaign."""

    added: Tuple[RouteChange, ...]
    modified: Tuple[RouteChange, ...]
    unchanged_count: int
    removed_count: int

    @property
    def targeted(self) -> Tuple[RouteOperation, ...]:
        return tuple(change.operation for change in (*self.added, *self.modified))

    def as_dict(self) -> Dict[str, Any]:
        return {
            "baseline_operation_count": self.unchanged_count + len(self.modified) + self.removed_count,
            "sut_operation_count": self.unchanged_count + len(self.modified) + len(self.added),
            "target_operation_count": len(self.targeted),
            "added": [change.as_dict() for change in self.added],
            "modified": [change.as_dict() for change in self.modified],
            "unchanged_count": self.unchanged_count,
            "removed_count": self.removed_count,
        }


@dataclass(frozen=True)
class SutRouteSelection:
    """SUT-owned operations selected from the broader activation delta."""

    sut_slug: str
    owned_added: Tuple[RouteChange, ...]
    owned_modified: Tuple[RouteChange, ...]
    shared_modified: Tuple[RouteChange, ...]

    @property
    def targeted(self) -> Tuple[RouteOperation, ...]:
        return tuple(
            change.operation for change in (*self.owned_added, *self.owned_modified)
        )

    def as_dict(self, diff: RouteDiff) -> Dict[str, Any]:
        owned = [
            {
                **change.as_dict(),
                "ownership": "sut",
                "ownership_evidence": ["activation_added"],
            }
            for change in self.owned_added
        ]
        owned.extend(
            {
                **change.as_dict(),
                "ownership": "sut",
                "ownership_evidence": list(
                    _sut_ownership_evidence(change.operation, self.sut_slug)
                ),
            }
            for change in self.owned_modified
        )
        shared = [
            {
                **change.as_dict(),
                "ownership": "shared",
                "ownership_evidence": ["activation_modified_shared_callback"],
            }
            for change in self.shared_modified
        ]
        return {
            "scope": "sut_owned",
            "baseline_operation_count": diff.unchanged_count
            + len(diff.modified)
            + diff.removed_count,
            "sut_operation_count": diff.unchanged_count
            + len(diff.modified)
            + len(diff.added),
            "target_operation_count": len(self.targeted),
            "sut_owned_operation_count": len(self.targeted),
            "shared_modified_operation_count": len(self.shared_modified),
            "excluded_operation_count": len(self.shared_modified),
            "activation_added_operation_count": len(diff.added),
            "activation_modified_operation_count": len(diff.modified),
            "added": [change.as_dict() for change in diff.added],
            "modified": [change.as_dict() for change in diff.modified],
            "sut_owned": owned,
            "shared_modified": shared,
            "unchanged_count": diff.unchanged_count,
            "removed_count": diff.removed_count,
        }


def _source_belongs_to_sut(source: Mapping[str, Any], sut_slug: str) -> bool:
    path = str(source.get("file", "")).replace("\\", "/").lower()
    slug = sut_slug.lower()
    return bool(path) and (
        f"/wp-content/plugins/{slug}/" in path
        or f"/plugins/{slug}/" in path
        or path.startswith(f"/{slug}/")
    )


def _sut_ownership_evidence(operation: RouteOperation, sut_slug: str) -> Tuple[str, ...]:
    evidence: List[str] = []
    for name, source in (
        ("callback_file", operation.callback),
        ("permission_callback_file", operation.permission_callback),
        ("registration_source_file", operation.registration_source),
    ):
        if _source_belongs_to_sut(source, sut_slug):
            evidence.append(name)
    return tuple(evidence)


def select_sut_owned_routes(diff: RouteDiff, sut_slug: str) -> SutRouteSelection:
    """Select primary campaign routes and retain shared modifications as diagnostics.

    Routes absent from the baseline are caused by activating the SUT and are therefore primary
    targets even when they delegate to a shared controller. Existing operations are primary only
    when their dispatched callback metadata points into the SUT. Schema or argument changes to a
    WooCommerce / WordPress controller remain visible as shared integration changes but do not
    consume the SUT-only campaign budget.
    """

    owned_modified: List[RouteChange] = []
    shared_modified: List[RouteChange] = []
    for change in diff.modified:
        if _sut_ownership_evidence(change.operation, sut_slug):
            owned_modified.append(change)
        else:
            shared_modified.append(change)

    return SutRouteSelection(
        sut_slug=sut_slug,
        owned_added=diff.added,
        owned_modified=tuple(owned_modified),
        shared_modified=tuple(shared_modified),
    )


def normalize_route_document(document: Mapping[str, Any]) -> Dict[str, RouteOperation]:
    """Flatten the MU plugin's route export into stable METHOD+route operations.

    The exporter deliberately preserves WordPress method aliases and handler groups. This
    function expands aliases, ignores the automatically served HEAD/OPTIONS methods, and hashes
    only behaviorally relevant metadata so baseline/SUT comparisons are reproducible.
    """

    routes = document.get("routes", {})
    if not isinstance(routes, Mapping):
        raise ValueError("Route export must contain a 'routes' object")

    operations: Dict[str, RouteOperation] = {}
    for raw_route in sorted(routes, key=str):
        route = str(raw_route)
        raw_handlers = routes[raw_route]
        if isinstance(raw_handlers, Mapping):
            handlers: Sequence[Any] = (raw_handlers,)
        elif isinstance(raw_handlers, Sequence) and not isinstance(raw_handlers, (str, bytes)):
            handlers = raw_handlers
        else:
            continue

        for raw_handler in handlers:
            if not isinstance(raw_handler, Mapping):
                continue
            for method in _normalize_methods(raw_handler.get("methods")):
                operation = RouteOperation.create(method, route, raw_handler)
                # WordPress can register multiple callbacks for the same method and route. The
                # REST server returns the first eligible handler, so later shadowed callbacks
                # must not create a false activation delta.
                operations.setdefault(operation.key, operation)

    return dict(sorted(operations.items()))


def diff_route_documents(baseline: Mapping[str, Any], sut: Mapping[str, Any]) -> RouteDiff:
    """Return operations added or behaviorally modified by activating the SUT."""

    before = normalize_route_document(baseline)
    after = normalize_route_document(sut)
    added: List[RouteChange] = []
    modified: List[RouteChange] = []
    unchanged_count = 0

    for key, operation in after.items():
        previous = before.get(key)
        if previous is None:
            added.append(RouteChange("added", operation))
        elif previous.fingerprint != operation.fingerprint:
            changed_fields = tuple(
                name
                for name in (
                    "args",
                    "schema",
                    "callback",
                    "permission_callback",
                    "registration_source",
                )
                if getattr(previous, name) != getattr(operation, name)
            )
            modified.append(
                RouteChange(
                    "modified",
                    operation,
                    previous.fingerprint,
                    changed_fields,
                )
            )
        else:
            unchanged_count += 1

    removed_count = len(set(before) - set(after))
    return RouteDiff(tuple(added), tuple(modified), unchanged_count, removed_count)
