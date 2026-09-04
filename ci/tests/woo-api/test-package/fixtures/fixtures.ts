/**
 * Tag constants used for test filtering.
 *
 * Extracted from the WooCommerce Core test suite to avoid
 * pulling in the @woocommerce/e2e-utils-playwright dependency.
 */
export const tags = {
	GUTENBERG: '@gutenberg',
	SERVICES: '@services',
	PAYMENTS: '@payments',
	HPOS: '@hpos',
	SKIP_ON_EXTERNAL_ENV: '@skip-on-external-env',
	SKIP_ON_WPCOM: '@skip-on-wpcom',
	SKIP_ON_PRESSABLE: '@skip-on-pressable',
	COULD_BE_LOWER_LEVEL_TEST: '@could-be-lower-level-test',
	NON_CRITICAL: '@non-critical',
	TO_BE_REMOVED: '@to-be-removed',
	NOT_E2E: '@not-e2e',
	WP_CORE: '@wp-core',
	PAYPAL: '@paypal',
} as const;
