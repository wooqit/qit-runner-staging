<?php
/**
 * Plugin Name: QIT Activation Smoke Safe Fixture
 * Description: Known-answer control for the Activation hook-resilience smoke test.
 * Version: 1.0.0
 */

if (
	function_exists( 'qit_activation_smoke_is_request' )
	&& function_exists( 'qit_activation_smoke_contract' )
	&& qit_activation_smoke_is_request()
) {
	$contract    = qit_activation_smoke_contract();
	$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

	if ( 'rest_pre_serve_request:null' === $contract ) {
		add_filter( 'rest_pre_serve_request', 'qit_activation_smoke_safe_callback', 10, 4 );
	}
	if ( 'pre_http_request:wp_error' === $contract ) {
		add_filter( 'pre_http_request', 'qit_activation_smoke_safe_http_callback', 10, 3 );
	}
	if ( 'rest_authentication_errors:wp_error' === $contract ) {
		add_filter( 'rest_authentication_errors', 'qit_activation_smoke_safe_authentication_callback' );
	}
	if ( false !== strpos( $request_uri, 'admin.php?page=wc-settings' ) ) {
		add_filter( 'woocommerce_settings_tabs_array', 'qit_activation_smoke_safe_settings_callback' );
	}
	if ( false !== strpos( $request_uri, '/probes/wp-mail' ) ) {
		add_filter( 'wp_mail_from', 'qit_activation_smoke_safe_mail_from_callback' );
	}
}

/**
 * Accept the value produced by earlier callbacks and normalize it at the boundary.
 *
 * @param mixed $served Whether the REST response has already been served.
 *
 * @return bool
 */
function qit_activation_smoke_safe_callback( $served ): bool {
	return (bool) $served;
}

/**
 * Preserve every value allowed by the pre_http_request contract.
 *
 * @param mixed $response A preemptive HTTP response.
 *
 * @return mixed
 */
function qit_activation_smoke_safe_http_callback( $response ) {
	return $response;
}

/**
 * Preserve every value allowed by the REST authentication contract.
 *
 * @param mixed $errors Authentication result.
 *
 * @return mixed
 */
function qit_activation_smoke_safe_authentication_callback( $errors ) {
	return $errors;
}

/**
 * Accept the settings tabs array produced by WooCommerce.
 *
 * @param array<string,string> $tabs Settings tabs.
 *
 * @return array<string,string>
 */
function qit_activation_smoke_safe_settings_callback( array $tabs ): array {
	return $tabs;
}

/**
 * Accept the sender address produced by WordPress.
 *
 * @param string $from Sender address.
 */
function qit_activation_smoke_safe_mail_from_callback( string $from ): string {
	return $from;
}
