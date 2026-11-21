<?php
/**
 * Plugin Name: WooCommerce E2E Test Helper
 * Description: Port of WooCommerce Core's always-on E2E helper: cookie-driven filter overrides, synchronous Action Scheduler processing, and REST routes for feature flags, options, environment info and theme switching.
 *
 * Ported from `tests/e2e/test-plugins/woocommerce-e2e-test-helper` upstream, where
 * it is auto-activated for every run through the `.wp-env.e2e.json` "plugins"
 * array. QIT has no wp-env, so it ships as an mu-plugin listed in
 * `qit-test.json`. The REST routes are what the specs bind to
 * (`utils/features.ts`, `utils/options.ts`), so those are kept identical;
 * without them those helpers are dead code that 404s on first use.
 *
 * One deliberate change from upstream: every function is prefixed. Upstream
 * declares bare `is_allowed()`, `activate_theme()` and friends, which is safe in
 * a fixed environment but not here — QIT loads an arbitrary third-party
 * extension alongside this, mu-plugins load first, and a name collision would
 * fatal the extension under test and be reported as its fault.
 *
 * Supersedes the narrower `test-helper-apis.php`, which exposed the same
 * `e2e-options` routes.
 *
 * None of this should ever run in a production environment.
 *
 * @package qit-woo-e2e
 */

declare(strict_types=1);

/*
 * -----------------------------------------------------------------------------
 * Filter setter
 * -----------------------------------------------------------------------------
 *
 * Registers WordPress filters from an 'e2e-filters' cookie, so a spec can override
 * filtered values on the fly. The cookie is a JSON map of hook => spec:
 *
 *     { "woocommerce_system_timeout": 10 }
 *
 * A spec may instead name a callback and/or priority:
 *
 *     { "woocommerce_enable_deathray": { "callback": "__return_false", "priority": 20 } }
 *
 * or a literal value with a priority:
 *
 *     { "woocommerce_default_username": { "value": "Geoffrey", "priority": 20 } }
 */

/**
 * Read the `e2e-filters` cookie and register the filters it describes.
 */
function qit_e2e_apply_cookie_filters(): void {
	if ( ! isset( $_COOKIE['e2e-filters'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$filters = json_decode( $_COOKIE['e2e-filters'], true );

	if ( ! is_array( $filters ) ) {
		return;
	}

	foreach ( $filters as $hook => $spec ) {
		$priority = isset( $spec['priority'] ) && is_int( $spec['priority'] )
			? $spec['priority']
			: 10;

		unset( $value, $callback );

		if ( ! is_array( $spec ) ) {
			$value = $spec;
		} elseif ( isset( $spec['value'] ) ) {
			$value = $spec['value'];
		}

		if ( isset( $value ) ) {
			$callback = function () use ( $value ) {
				return $value;
			};
		}

		if ( is_array( $spec ) && isset( $spec['callback'] ) && is_string( $spec['callback'] ) ) {
			$callback = $spec['callback'];
		}

		if ( isset( $callback ) ) {
			add_filter( $hook, $callback, $priority );
		}
	}
}

qit_e2e_apply_cookie_filters();

/*
 * -----------------------------------------------------------------------------
 * Process waiting actions
 * -----------------------------------------------------------------------------
 *
 * Runs the Action Scheduler queue on demand, so scheduled data lands
 * synchronously rather than the spec waiting on cron.
 */
add_action(
	'init',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['process-waiting-actions'] ) ) {
			return;
		}

		if ( ! class_exists( ActionScheduler_QueueRunner::class ) ) {
			return;
		}

		exit( ActionScheduler_QueueRunner::instance()->run( 'E2E Tests' ) ? 1 : 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Test helper REST API
 * -----------------------------------------------------------------------------
 */

/**
 * Whether the current user may use these helpers.
 *
 * @return bool
 */
function qit_e2e_helper_is_allowed(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Merge the flags a spec stored into WooCommerce's feature config.
 *
 * @param array $features Feature config.
 * @return array
 */
function qit_e2e_enable_experimental_features( $features ) {
	$stored_features = get_option( 'e2e_feature_flags', array() );

	return array_merge( $features, $stored_features );
}

add_filter( 'woocommerce_admin_get_feature_config', 'qit_e2e_enable_experimental_features' );

/**
 * Disable WordPress comment flood protection during E2E runs.
 *
 * Specs post comments and reviews as the shared customer account, and core's
 * 15-second throttle ("You are posting comments too quickly") rejects whichever
 * request lands second — a flake unrelated to the behaviour under test.
 */
add_filter( 'comment_flood_filter', '__return_false', 99 );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'e2e-feature-flags',
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) {
					$features     = get_option( 'e2e_feature_flags', array() );
					$new_features = json_decode( $request->get_body(), true );

					if ( is_array( $new_features ) ) {
						update_option( 'e2e_feature_flags', array_merge( $features, $new_features ) );
						return new WP_REST_Response( 'Feature flags updated', 200 );
					}

					return new WP_REST_Response( 'Invalid request body', 400 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);

		register_rest_route(
			'e2e-feature-flags',
			'/reset',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					delete_option( 'e2e_feature_flags' );
					return new WP_REST_Response( 'Feature flags reset', 200 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);

		register_rest_route(
			'e2e-options',
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) {
					$option_name  = sanitize_text_field( $request['option_name'] );
					$option_value = sanitize_text_field( $request['option_value'] );

					if ( get_option( $option_name ) === $option_value ) {
						return new WP_REST_Response( 'Option ' . $option_name . ' already set to: ' . $option_value, 200 );
					}

					if ( update_option( $option_name, $option_value ) ) {
						return new WP_REST_Response( 'Update option SUCCESS: ' . $option_name . ' => ' . $option_value, 200 );
					}

					return new WP_REST_Response( 'Update option FAILED: ' . $option_name . ' => ' . $option_value, 400 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);

		register_rest_route(
			'e2e-options',
			'/delete',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) {
					$option_name = sanitize_text_field( $request['option_name'] );

					if ( null === get_option( $option_name, null ) ) {
						return new WP_REST_Response( 'Option ' . $option_name . ' does not exist.', 200 );
					}

					if ( delete_option( $option_name ) ) {
						return new WP_REST_Response( 'Delete option SUCCESS: ' . $option_name, 200 );
					}

					return new WP_REST_Response( 'Delete option FAILED: ' . $option_name, 400 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);

		register_rest_route(
			'e2e-environment',
			'/info',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';

					$data         = array();
					$data['Core'] = get_bloginfo( 'version' );
					$data['PHP']  = sprintf( '%s.%s', PHP_MAJOR_VERSION, PHP_MINOR_VERSION );

					foreach ( get_plugins() as $plugin_file => $plugin_data ) {
						if ( is_plugin_active( $plugin_file ) ) {
							$data[ $plugin_data['Name'] ] = $plugin_data['Version'];
						}
					}

					return new WP_REST_Response( $data, 200 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);

		register_rest_route(
			'e2e-theme',
			'/activate',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) {
					$theme_name = sanitize_text_field( $request['theme_name'] );

					if ( empty( $theme_name ) ) {
						return new WP_REST_Response( array( 'message' => 'Theme name is empty.' ), 400 );
					}

					if ( ! wp_get_theme( $theme_name )->exists() ) {
						return new WP_REST_Response( array( 'message' => "Theme '$theme_name' does not exist." ), 400 );
					}

					switch_theme( $theme_name );

					return new WP_REST_Response( array( 'message' => "Theme '$theme_name' activated successfully." ), 200 );
				},
				'permission_callback' => 'qit_e2e_helper_is_allowed',
			)
		);
	}
);
