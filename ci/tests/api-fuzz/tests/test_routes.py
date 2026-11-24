import unittest

from qit_api_fuzz.routes import (
    diff_route_documents,
    normalize_route_document,
    select_sut_owned_routes,
)


class RouteNormalizationTest(unittest.TestCase):
    def test_expands_wordpress_method_aliases_and_ignores_automatic_methods(self):
        document = {
            "routes": {
                "/wc/v3/subscriptions/(?P<id>[\\d]+)": [
                    {
                        "methods": {"READABLE": True, "EDITABLE": True, "OPTIONS": True},
                        "callback": {
                            "class": "WC_REST_Subscriptions_Controller",
                            "method": "get_item",
                            "file": "/sut/includes/rest/class-controller.php",
                        },
                        "args": {"id": {"type": "integer", "required": True}},
                    }
                ]
            }
        }

        operations = normalize_route_document(document)

        self.assertEqual(
            [
                "GET /wc/v3/subscriptions/(?P<id>[\\d]+)",
                "PATCH /wc/v3/subscriptions/(?P<id>[\\d]+)",
                "PUT /wc/v3/subscriptions/(?P<id>[\\d]+)",
            ],
            list(operations),
        )

    def test_selection_targets_added_routes_and_excludes_shared_modifications(self):
        baseline = {
            "routes": {
                "/wc/v3/orders": [{"methods": ["GET"], "args": {"page": {"type": "integer"}}}],
                "/wp/v2/users": [{"methods": ["GET"], "args": {}}],
            }
        }
        with_sut = {
            "routes": {
                # This route is absent from the baseline, so SUT activation introduced it even
                # though the callback delegates to a shared WooCommerce controller.
                "/wc/v3/subscriptions": [
                    {
                        "methods": ["GET"],
                        "callback": {
                            "declaring_class": "WC_REST_CRUD_Controller",
                            "file": "/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-crud-controller.php",
                        },
                        "args": {"status": {"type": "string"}},
                    }
                ],
                "/wc/v3/orders": [
                    {
                        "methods": ["GET"],
                        "args": {
                            "page": {"type": "integer"},
                            "subscription_id": {"type": "integer"},
                        },
                    }
                ],
                "/wp/v2/users": [{"methods": ["GET"], "args": {}}],
            }
        }

        diff = diff_route_documents(baseline, with_sut)
        selection = select_sut_owned_routes(diff, "woocommerce-subscriptions")

        self.assertEqual(["GET /wc/v3/subscriptions"], [change.operation.key for change in diff.added])
        self.assertEqual(["GET /wc/v3/orders"], [change.operation.key for change in diff.modified])
        self.assertEqual(("args",), diff.modified[0].changed_fields)
        self.assertEqual(1, diff.unchanged_count)
        self.assertEqual(0, diff.removed_count)
        self.assertEqual(2, diff.as_dict()["target_operation_count"])
        self.assertEqual(
            ["GET /wc/v3/subscriptions"],
            [operation.key for operation in selection.targeted],
        )
        self.assertEqual(
            ["GET /wc/v3/orders"],
            [change.operation.key for change in selection.shared_modified],
        )
        discovery = selection.as_dict(diff)
        self.assertEqual("sut_owned", discovery["scope"])
        self.assertEqual(1, discovery["target_operation_count"])
        self.assertEqual(1, discovery["shared_modified_operation_count"])
        self.assertEqual(["activation_added"], discovery["sut_owned"][0]["ownership_evidence"])

    def test_selection_includes_modified_routes_dispatched_by_the_sut(self):
        baseline = {
            "routes": {
                "/wc/v3/subscriptions": [
                    {
                        "methods": ["GET"],
                        "callback": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce/controller.php"
                        },
                        "registration_source": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce/controller.php"
                        },
                        "args": {"page": {"type": "integer"}},
                    }
                ]
            }
        }
        with_sut = {
            "routes": {
                "/wc/v3/subscriptions": [
                    {
                        "methods": ["GET"],
                        "callback": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce-subscriptions/controller.php"
                        },
                        "registration_source": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce-subscriptions/controller.php"
                        },
                        "args": {"page": {"type": "integer"}},
                    }
                ]
            }
        }

        diff = diff_route_documents(baseline, with_sut)
        selection = select_sut_owned_routes(diff, "woocommerce-subscriptions")

        self.assertEqual(
            ["GET /wc/v3/subscriptions"],
            [change.operation.key for change in selection.owned_modified],
        )
        self.assertEqual((), selection.shared_modified)
        self.assertEqual(
            ["callback_file", "registration_source_file"],
            selection.as_dict(diff)["sut_owned"][0]["ownership_evidence"],
        )

    def test_woocommerce_files_are_sut_owned_when_woocommerce_is_the_sut(self):
        baseline = {
            "routes": {
                "/wc/store/cart": [
                    {"methods": ["GET"], "args": {"context": {"type": "string"}}}
                ]
            }
        }
        with_sut = {
            "routes": {
                "/wc/store/cart": [
                    {
                        "methods": ["GET"],
                        "callback": {
                            "file": "/var/www/html/wp-content/plugins/woocommerce/src/StoreApi/Routes/V1/Cart.php"
                        },
                        "args": {"context": {"type": "string"}},
                    }
                ]
            }
        }

        diff = diff_route_documents(baseline, with_sut)
        selection = select_sut_owned_routes(diff, "woocommerce")

        self.assertEqual(["GET /wc/store/cart"], [operation.key for operation in selection.targeted])
        self.assertEqual((), selection.shared_modified)

    def test_rejects_invalid_route_export(self):
        with self.assertRaisesRegex(ValueError, "routes"):
            normalize_route_document({"routes": []})

    def test_uses_the_first_handler_wordpress_will_dispatch(self):
        document = {
            "routes": {
                "/example/v1/items": [
                    {"methods": ["GET"], "callback": {"function": "first_handler"}},
                    {"methods": ["GET"], "callback": {"function": "shadowed_handler"}},
                ]
            }
        }

        operations = normalize_route_document(document)

        self.assertEqual(
            "first_handler",
            operations["GET /example/v1/items"].callback["function"],
        )


if __name__ == "__main__":
    unittest.main()
