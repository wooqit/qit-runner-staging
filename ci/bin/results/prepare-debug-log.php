<?php

$env = getenv();

$required_envs = [
	'GITHUB_WORKSPACE',
	'PHP_VERSION',
	'WP_VERSION',
	'SUT_SLUG',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

// PHP Errors.
$debug_log_file     = $env['GITHUB_WORKSPACE'] . '/ci/debug.log';
$new_debug_log_file = $env['GITHUB_WORKSPACE'] . '/ci/debug_prepared.log';

if ( file_exists( $debug_log_file ) ) {
	$log_entries = [];
	$debug_log   = new SplFileObject( $debug_log_file, 'r' );

	$current_entry      = '';
	$skip_current_entry = false;

	// Process each line of the debug log
	while ( $debug_log->valid() ) {
		$line = strip_tags( $debug_log->current() );

		// Convert Unicode escape sequences
		$line = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function ( $matches ) {
			return mb_chr( hexdec( $matches[1] ), 'UTF-8' );
		}, $line );

		// Decode HTML entities
		$line = html_entity_decode( $line, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Remove double quotes
		$line = str_replace( '"', '', $line );

		// Check if the line starts with a timestamp
		if ( preg_match( '/^\[.*\]\s+(.*)$/', $line, $matches ) ) {
			// Process the previous entry if it's not skipped
			if ( ! empty( $current_entry ) ) {
				if ( ! $skip_current_entry ) {
					// Trim the current entry
					$normalized_entry = trim( $current_entry );

					$hash = md5( $normalized_entry );
					if ( ! array_key_exists( $hash, $log_entries ) ) {
						$log_entries[ $hash ] = [
							'count'   => 0,
							'message' => $normalized_entry,
						];
					}
					$log_entries[ $hash ]['count'] ++;
				}
				// Reset for the next entry
				$current_entry      = '';
				$skip_current_entry = false;
			}

			// Start a new entry
			$current_entry = $matches[1];

			// Apply skip conditions to the new entry
			$is_uncaught_exception = stripos( $current_entry, 'Uncaught' ) !== false;
			$is_fatal_error        = stripos( $current_entry, 'Fatal error' ) !== false;

			// Skip specific error messages
			if ( $is_uncaught_exception && stripos( $current_entry, 'The quantity of' ) !== false ) {
				$skip_current_entry = true;
			}

			if (
				stripos( $current_entry, 'phar://' ) !== false // Ignore notices that come from Phar context, which can be triggered by WP CLI.
				|| empty( trim( $current_entry ) ) // Ignore empty lines.
				|| stripos( $current_entry, '/var/www/html/wp-content/mu-plugins/' ) !== false // Ignore notices from test site mu-plugins.
			) {
				$skip_current_entry = true;
			}

			// Ignore errors coming from WooCommerce Core if not testing WooCommerce Core, unless it's a fatal error or uncaught exception.
			$error_from_woocommerce_core = stripos( $current_entry, 'in /var/www/html/wp-content/plugins/woocommerce/' ) !== false;
			$is_testing_woocommerce_core = in_array( $env['SUT_SLUG'], [ 'woocommerce', 'wporg-woocommerce' ], true );
			$has_sut_slug_in_error       = stripos( $current_entry, $env['SUT_SLUG'] ) !== false;

			if ( $error_from_woocommerce_core && ! $is_testing_woocommerce_core && ! $has_sut_slug_in_error && ! $is_fatal_error && ! $is_uncaught_exception ) {
				$skip_current_entry = true;
			}

			// Ignore errors coming from wp-mail-logging, if it's not the SUT. This is a plugin we install on all E2E tests.
			$error_from_wp_mail_logging = stripos( $current_entry, 'in /var/www/html/wp-content/plugins/wp-mail-logging/' ) !== false;
			$is_testing_wp_mail_logging = $env['SUT_SLUG'] === 'wp-mail-logging';

			if ( $error_from_wp_mail_logging && ! $is_testing_wp_mail_logging && ! $has_sut_slug_in_error ) {
				$skip_current_entry = true;
			}

			// Ignore deprecated usage of "wc_get_min_max_price_meta_query" that comes from Woo Core if not testing Woo.
			// Open bug report: https://github.com/woocommerce/woocommerce/issues/39380
			$is_deprecated_wc_get_min_max = stripos( $current_entry, 'The wc_get_min_max_price_meta_query() function is deprecated since version 3.6' ) !== false;

			// Ignore deprecations coming from Woo Core if not testing Woo.
			// Bug report: https://github.com/woocommerce/woocommerce/issues/37592#issuecomment-1824643133
			$is_deprecated_admin_options = stripos( $current_entry, 'Options::get_options function is deprecated since version' ) !== false;

			// Ignore deprecations coming from Woo Core if not testing Woo.
			// Bug report: https://github.com/woocommerce/woocommerce/pull/41434
			$is_deprecated_compatible_plugins = stripos( $current_entry, 'Function FeaturesController::get_compatible_plugins_for_feature was called incorrectly' ) !== false;

			if (
				( $is_deprecated_wc_get_min_max || $is_deprecated_admin_options || $is_deprecated_compatible_plugins )
				&& ! $is_testing_woocommerce_core
				&& ! $has_sut_slug_in_error
			) {
				$skip_current_entry = true;
			}

			$is_wp_mail_logging_load_textdomain = stripos( $current_entry, 'Function wp_mail_logging_load_textdomain was called incorrectly' ) !== false || stripos( $current_entry, 'Translation loading for the wp-mail-logging domain was triggered too early.' ) !== false;

			if ( $is_wp_mail_logging_load_textdomain && ! $has_sut_slug_in_error ) {
				$skip_current_entry = true;
			}

			/*
			 * Ignore exif_read_data() notices such as this:
			 * exif_read_data(single-1.jpg): Incorrect APP1 Exif Identifier Code in \/var\/www\/html\/wp-admin\/includes\/image.php on line 912
			 * @see https://core.trac.wordpress.org/ticket/42480
			 * @see https://github.com/WordPress/wordpress-develop/blame/trunk/src/wp-admin/includes/image.php#L909-L917
			 */
			if ( stripos( $current_entry, 'exif_read_data' ) !== false ) {
				$skip_current_entry = true;
			}

			// "PHP Notice: A feed could not be found at `https:\/\/wordpress.org\/news\/feed\/`; the status code is `429` and content-type is `text\/html` in \/var\/www\/html\/wp-includes\/class-simplepie.php on line 1786"
			$is_wordpress_org_notice_and_has_429 = stripos( $current_entry, 'wordpress.org' ) !== false && stripos( $current_entry, '429' ) !== false;
			if ( $is_wordpress_org_notice_and_has_429 ) {
				$skip_current_entry = true;
			}

			/*
			 * If we are running PHP 8+ on WordPress 6.1 or lower, ignore the following notices.
			 * @link https://core.trac.wordpress.org/ticket/54504
			 */
			if ( version_compare( $env['PHP_VERSION'], '8', '>=' ) && version_compare( $env['WP_VERSION'], '6.2', '<' ) ) {
				if (
					stripos( $current_entry, 'attribute should be used to temporarily suppress the notice in /var/www/html/wp-includes/Requests/Cookie/Jar.php' ) !== false
					|| stripos( $current_entry, 'attribute should be used to temporarily suppress the notice in /var/www/html/wp-includes/Requests/Utility/CaseInsensitiveDictionary.php' ) !== false
					|| ( stripos( $current_entry, 'PHP Deprecated: http_build_query()' ) !== false && stripos( $current_entry, 'wp-includes/Requests/Transport/cURL.php' ) !== false )
				) {
					$skip_current_entry = true;
				}
			}
		} else {
			// Append the line to the current entry
			$current_entry .= "\n" . $line;
		}

		// Move to the next line
		$debug_log->next();
	}

	// Process the last entry if it's not empty
	if ( ! empty( $current_entry ) && ! $skip_current_entry ) {
		// Trim the current entry
		$normalized_entry = trim( $current_entry );

		$hash = md5( $normalized_entry );
		if ( ! array_key_exists( $hash, $log_entries ) ) {
			$log_entries[ $hash ] = [
				'count'   => 0,
				'message' => $normalized_entry,
			];
		}
		$log_entries[ $hash ]['count'] ++;
	}

	$debug_path = $env['GITHUB_WORKSPACE'] . '/ci/debug_prepared.log';
	$written    = file_put_contents( $debug_path, json_encode( $log_entries ) );

	if ( ! $written ) {
		echo "Failed to write $new_debug_log_file";
		die( 1 );
	} else {
		echo "Wrote debug contents to: $debug_path";
	}
} else {
	echo "$debug_log_file does not exist for prepare.";
}