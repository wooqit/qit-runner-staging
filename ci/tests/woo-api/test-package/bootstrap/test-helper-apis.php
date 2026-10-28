<?php
/**
 * Plugin Name: Test Helper APIs
 * Description: Utility REST API for E2E/API testing. Allows setting and deleting WordPress options via REST.
 */

add_action( 'rest_api_init', function () {
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
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
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
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
} );
