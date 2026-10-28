<?php

namespace CI_CLI;

function validate_env_vars( array $env_vars ): void {
	foreach ( $env_vars as $env_var ) {
		$var = getenv( $env_var );
		if ( ! isset( $var ) ) {
			throw new \Exception( "Missing environment variable {$env_var}." );
		}
	}
}

function qit_decrypt_for_ci( string $data ): string {
	if ( empty( $data ) ) {
		return $data;
	}

	$encryption_key = getenv('CI_ENCRYPTION_KEY' );
	$method         = 'aes-256-cbc';

	// Split the data from the IV based on the '::QIT_IV::' separator.
	[ $encrypted_data, $iv ] = explode( '::QIT_IV::', base64_decode( $data ), 2 );

	// Decrypt the data
	$decrypted = openssl_decrypt( $encrypted_data, $method, $encryption_key, 0, $iv );

	return $decrypted;
}

function get_manager_secret(): string {
	if ( stripos( getenv('MANAGER_HOST' ), 'stagingcompatibilitydashboard' ) !== false ) {
		return getenv('CI_STAGING_SECRET' );
	} else {
		return getenv('CI_SECRET' );
	}
}