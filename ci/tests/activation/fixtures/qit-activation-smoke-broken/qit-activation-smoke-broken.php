<?php
/**
 * Plugin Name: QIT Activation Smoke Broken Fixture
 * Description: Known-answer TypeError fixture for the Activation hook-resilience smoke test.
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
		add_filter( 'rest_pre_serve_request', 'qit_activation_smoke_broken_callback', 10, 4 );
	}
	if ( 'pre_http_request:wp_error' === $contract ) {
		add_filter( 'pre_http_request', 'qit_activation_smoke_broken_http_callback', 10, 3 );
	}
	if ( 'rest_authentication_errors:wp_error' === $contract ) {
		add_filter( 'rest_authentication_errors', 'qit_activation_smoke_broken_authentication_callback' );
	}
	if ( false !== strpos( $request_uri, 'admin.php?page=wc-settings' ) ) {
		add_filter( 'woocommerce_settings_tabs_array', 'qit_activation_smoke_broken_settings_callback' );
	}
	if ( false !== strpos( $request_uri, '/probes/wp-mail' ) ) {
		add_filter( 'wp_mail_from', 'qit_activation_smoke_broken_mail_from_callback' );
	}
}

/**
 * Intentionally reject a value that another valid filter callback can produce.
 *
 * @param bool $served Whether the REST response has already been served.
 *
 * @return bool
 */
function qit_activation_smoke_broken_callback( bool $served ): bool {
	return $served;
}

/**
 * Intentionally assume every non-false HTTP result is an array.
 *
 * @param mixed $response A preemptive HTTP response.
 *
 * @return mixed
 */
function qit_activation_smoke_broken_http_callback( $response ) {
	if ( false !== $response ) {
		$response['qit_probe_touched'] = true;
	}

	return $response;
}

/**
 * Intentionally omit WP_Error from the documented authentication domain.
 */
function qit_activation_smoke_broken_authentication_callback( ?bool $errors ): ?bool {
	return $errors;
}

/**
 * Intentionally reject the settings array supplied by WooCommerce.
 *
 * @return array<string,string>
 */
function qit_activation_smoke_broken_settings_callback( string $tabs ): array {
	return [ $tabs => $tabs ];
}

/**
 * Intentionally reject the sender string supplied by WordPress.
 *
 * @param array<string,string> $from Sender address.
 */
function qit_activation_smoke_broken_mail_from_callback( array $from ): string {
	return implode( ',', $from );
}
