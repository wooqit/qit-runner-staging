import unittest

from qit_api_fuzz.openapi import build_openapi_document
from qit_api_fuzz.routes import normalize_route_document


def operations(document):
    return normalize_route_document(document).values()


class OpenApiConversionTest(unittest.TestCase):
    def test_converts_rich_subscriptions_style_schema(self):
        route_export = {
            "routes": {
                "/wc/v3/subscriptions/(?P<id>[\\d]+)": [
                    {
                        "methods": ["EDITABLE"],
                        "args": {
                            "id": {"type": "integer", "required": True},
                            "status": {
                                "type": "string",
                                "enum": ["active", "cancelled"],
                                "required": True,
                            },
                            "schedule_next_payment": {"type": "date-time"},
                            "line_items": {
                                "type": "array",
                                "items": {
                                    "type": "object",
                                    "properties": {"product_id": {"type": "integer"}},
                                },
                            },
                        },
                    }
                ]
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test/wp-json")
        operation = converted.document["paths"]["/wc/v3/subscriptions/{id}"]["put"]
        path_parameter = operation["parameters"][0]
        body = operation["requestBody"]["content"]["application/json"]["schema"]

        self.assertEqual("3.0.3", converted.document["openapi"])
        self.assertEqual([{"url": "http://qit.test/wp-json"}], converted.document["servers"])
        self.assertEqual("id", path_parameter["name"])
        self.assertEqual("integer", path_parameter["schema"]["type"])
        self.assertEqual(["active", "cancelled"], body["properties"]["status"]["enum"])
        self.assertEqual("date-time", body["properties"]["schedule_next_payment"]["format"])
        self.assertEqual("integer", body["properties"]["line_items"]["items"]["properties"]["product_id"]["type"])
        self.assertEqual(["status"], body["required"])
        self.assertEqual("complete", converted.operation_reports[0]["usability"])

    def test_reports_sparse_bookings_style_schema_and_synthesizes_path_capture(self):
        route_export = {
            "routes": {
                "/wc-bookings/v1/products/(?P<id>[\\d]+)/slots": [
                    {
                        "methods": ["GET"],
                        # The real Bookings slots callback reads several values that are not
                        # declared. Only its regex capture is available to the converter.
                        "args": {},
                    }
                ]
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")
        operation = converted.document["paths"]["/wc-bookings/v1/products/{id}/slots"]["get"]
        report = converted.operation_reports[0]

        self.assertEqual("untyped", report["usability"])
        self.assertIn("path.id:missing_wordpress_arg_metadata", report["lost_constraints"])
        self.assertEqual("id", operation["parameters"][0]["name"])
        self.assertEqual("[\\d]+", operation["parameters"][0]["schema"]["pattern"])

    def test_normalizes_extension_schema_objects_nested_under_type(self):
        route_export = {
            "routes": {
                "/wc-bookings/v1/resources/batch": [
                    {
                        "methods": ["POST"],
                        "args": {
                            "availability": {
                                "type": {
                                    "description": "Resource availability.",
                                    "type": "string",
                                    "readonly": True,
                                    "context": ["view"],
                                }
                            }
                        },
                    }
                ]
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")
        operation = converted.document["paths"]["/wc-bookings/v1/resources/batch"]["post"]
        availability = operation["requestBody"]["content"]["application/json"]["schema"][
            "properties"
        ]["availability"]

        self.assertEqual("string", availability["type"])
        self.assertEqual("Resource availability.", availability["description"])
        self.assertIn(
            "arg.availability:nested_schema_in_type",
            converted.operation_reports[0]["lost_constraints"],
        )

    def test_normalizes_associative_wordpress_enum_to_openapi_array(self):
        route_export = {
            "routes": {
                "/wp/v2/users": [
                    {
                        "methods": ["GET"],
                        "args": {
                            "has_published_posts": {
                                "type": "array",
                                "items": {
                                    "type": "string",
                                    "enum": {"post": "post", "page": "page"},
                                },
                            }
                        },
                    }
                ]
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")
        parameter = converted.document["paths"]["/wp/v2/users"]["get"]["parameters"][0]

        self.assertEqual(["post", "page"], parameter["schema"]["items"]["enum"])
        self.assertIn(
            "arg.has_published_posts.items:associative_enum_normalized",
            converted.operation_reports[0]["lost_constraints"],
        )

    def test_preserves_get_as_a_campaign_target_because_get_can_have_side_effects(self):
        route_export = {
            "routes": {
                "/subscriptions/v1/queue": [
                    {
                        "methods": ["GET", "POST", "PUT"],
                        "args": {"action": {"type": "string", "required": True}},
                    }
                ]
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")

        self.assertEqual({"get", "post", "put"}, set(converted.document["paths"]["/subscriptions/v1/queue"]))

    def test_excludes_routes_with_unrepresentable_regex_from_generated_schema(self):
        route_export = {
            "routes": {
                "/example/v1/(foo|bar)": [{"methods": ["GET"], "args": {}}],
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")

        self.assertEqual({}, converted.document["paths"])
        self.assertEqual("unsupported", converted.operation_reports[0]["usability"])

    def test_excludes_distinct_routes_that_collapse_to_the_same_path_and_method(self):
        route_export = {
            "routes": {
                "/example/v1/items/(?P<id>\\d+)": [
                    {"methods": ["GET"], "args": {"id": {"type": "integer"}}}
                ],
                "/example/v1/items/(?P<id>[0-9a-f]+)": [
                    {"methods": ["GET"], "args": {"id": {"type": "string"}}}
                ],
            }
        }

        converted = build_openapi_document(operations(route_export), "http://qit.test")
        expected_operations = sorted(
            [
                "GET /example/v1/items/(?P<id>\\d+)",
                "GET /example/v1/items/(?P<id>[0-9a-f]+)",
            ]
        )

        self.assertEqual({}, converted.document["paths"])
        self.assertEqual(0, converted.usable_operation_count)
        self.assertEqual(2, len(converted.operation_reports))
        for report in converted.operation_reports:
            self.assertEqual("unsupported", report["usability"])
            self.assertIn("openapi_path_method_collision", report["lost_constraints"])
            self.assertEqual(expected_operations, report["collision_operations"])


if __name__ == "__main__":
    unittest.main()
