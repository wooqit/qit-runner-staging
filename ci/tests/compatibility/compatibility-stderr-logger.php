<?php

global $wp_filter;

// add_filter alternative before WordPress is loaded.
$wp_filter['option_active_plugins'][10]['cd_deactivate_plugin_stderr'] = [
	'function'      => 'cd_deactivate_plugin_stderr',
	'accepted_args' => 1,
];

// Filter the multi-site option just in case.
$wp_filter['site_option_active_sitewide_plugins'][10]['cd_deactivate_plugin_stderr'] = [
	'function'      => 'cd_deactivate_plugin_stderr',
	'accepted_args' => 1,
];

// Prevent plugins causing errors from tampering with this request.
function cd_deactivate_plugin_stderr( $plugins ) {
	// Disable all plugins in this request.
	return [];
}

define( 'QIT_STDERR_LOGGER', true );

require '/var/www/html/wp-load.php';

$errors = explode( "\n", file_get_contents( WPMU_PLUGIN_DIR . '/cd-stderr.txt' ) );

foreach ( $errors as $err ) {
	if ( ! empty( trim( $err ) ) ) {
		/*
		 * We don't have access to the error code, only the string. This is because the error
		 * was triggered on a different request, and we are logging the error string only.
		 * Therefore, we need to infer the error type here the best that we can.
		 */
		if ( stripos( $err, 'PHP Parse error' ) ) {
			$error_code = E_USER_ERROR;
		} elseif ( stripos( $err, 'PHP Fatal error' ) ) {
			$error_code = E_USER_ERROR;
		} elseif ( stripos( $err, 'PHP Warning' ) ) {
			$error_code = E_USER_WARNING;
		} else {
			$error_code = E_USER_NOTICE;
		}

		trigger_error( trim( str_replace( '{CD_LINE}', "\n", str_replace( '{CD_STDERR}', '', $err ) ) ), $error_code );
	}
}