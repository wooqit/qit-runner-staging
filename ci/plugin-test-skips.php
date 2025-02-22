<?php

/**
 * CI uses this file to determine which tests to skip when a given extension exists
 * in the PLUGIN_ACTIVATION_STACK. When a test fails in CI against a given extension,
 * we can disable that specific test here until we update our E2E tests accordingly.
 *
 * FORMAT:
 * [
 *        'tag' => [
 *            'extension-slug' => [
 *                'e2e/tests/admin-tasks/payment.spec.js' => [
 *                    'Payment setup task',
 *                ]
 *            ]
 *        ]
 * ]
 */

if ( ! isset( $qit_woocommerce_version, $qit_wordpress_version, $qit_test_type ) ) {
	throw new Exception( 'This file must be called from add-runtime-test-skips.php' );
}

switch ( $qit_test_type ) {
	case 'woo-e2e':
		$data = [
			'qit-skip' => [
				'woocommerce-payments'                        => [
					'tests/admin-tasks/payment.spec.js' => [
						'Payment setup task'
					]
				],
				'woocommerce-gateway-paypal-express-checkout' => [
					'tests/admin-tasks/payment.spec.js' => [
						'Payment setup task'
					]
				],
			]
		];

		// Woo 8.4.0 has an issue with shipping methods that will be fixed in 8.4.1. Since this has been flagged already, we will skip these tests for 8.4.0 exclusively.
		if ( version_compare( $qit_woocommerce_version, '8.3.999', '>' ) && version_compare( $qit_woocommerce_version, '8.4.1', '<' ) ) {
			$data['qit-skip']['all']['tests/merchant/create-shipping-zones.spec.js'] = [
				'add shipping zone for Mayne Island with free Local pickup',
				'add shipping zone for British Columbia with Free shipping',
				'add shipping zone for Canada with Flat rate',
				'add and delete shipping method',
				'allows customer to benefit from a free Free shipping if in BC',
				'allows customer to pay for a Flat rate shipping method',
			];

			$data['qit-skip']['all']['shopper/cart-block-calculate-shipping.spec.js'] = [
				'allows customer to calculate Free Shipping in cart block if in Netherlands',
			];
		}

		// Woo E2E tests are currently incompatible with 6.5-beta1 WordPress version.
		if ( version_compare( $qit_wordpress_version, '6.4.999', '>' ) && version_compare( $qit_wordpress_version, '6.5.999', '<' ) ) {
			$data['qit-skip']['all']['tests/merchant/command-palette.spec.js'] = [
				'can use the "Add new product" command',
				'can use the "Add new order" command',
				'can use the "Products" command',
				'can use the "Orders" command',
				'can use the product search command',
				'can use a settings command',
				'can use an analytics command',
			];
		}

		return $data;
		break;
	case 'woo-api':
		return [
			'qit-skip' => [
				'all'                  => [
					'tests/api-tests/data/data-crud.test.js'                   => [
						'can view all continents',
						'can view continent data',
						/*
						 * This is broken in 8.1.0-a.5, a new commit was pushed in Woo, and we can remove it on 8.1.0-a.6 or later.
						 * Relevant commit: https://github.com/Automattic/staging-compatibility-dashboard/actions/runs/6122121658/job/16617229761
						 */
						'can view all currencies',
					],
					'tests/api-tests/system-status/system-status-crud.test.js' => [
						'can view all system status items'
					]
				],
				'woocommerce-payments' => [
					'tests/api-tests/settings/settings-crud.test.js' => [
						'can retrieve all products settings'
					],
					'tests/api-tests/products/products-crud.test.js' => [
						'can add a product variation',
						'can retrieve a product variation',
						'can retrieve all product variations',
						'can update a product variation',
						'can permanently delete a product variation',
						'can batch update product variations',
						'can view a single product',
						'can update a single product',
						'can delete a product',
					]
				],
			]
		];
		break;
	default:
		throw new Exception( 'Unknown test type.' );
}