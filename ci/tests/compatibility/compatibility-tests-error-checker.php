<?php
/*
 * Plugin name: Compatibility Tests - Error Checker
 */
// Non-fatal error handler:
set_error_handler( 'cd_php_error_handler', error_reporting( E_ALL ) );

// Fatal error handler:
set_exception_handler( 'cd_php_exception_handler' );

// Parse error handler:
register_shutdown_function( 'cd_php_parse_error_handler' );

// DB error handler:
register_shutdown_function( 'cd_db_error_listener' );

foreach (
	[
		'wp_die_ajax_handler',
		'wp_die_json_handler',
		'wp_die_jsonp_handler',
		'wp_die_xmlrpc_handler',
		'wp_die_xml_handler',
		'wp_die_handler',
	] as $filter
) {
	add_filter( $filter, static function ( $original_handler ) {
		global $cd_original_handler;
		$cd_original_handler = $original_handler;

		return 'cd_die_handler';
	}, 1 );
}

/**
 * This function intercepts any calls to wp_die (and their variations),
 * log the call, and forward it to the original handler to keep the original
 * behavior.
 *
 * @param $message
 * @param $title
 * @param $args
 *
 * @return void
 */
function cd_die_handler( $message = '', $title = '', $args = '' ) {
	global $cd_original_handler;

	/*
	 * WooCommerce Background Processing uses wp_die()
	 * to kill a request in some scenarios, such as when
	 * the processing queue is empty. This is not an
	 * error so let's ignore it.
	 *
	 * We also ignore empty wp_die() calls from admin-ajax.php.
	 *
	 * @see \WP_Background_Process::maybe_handle
	 * @see \WP_Async_Request::maybe_handle
	 */
	if ( empty( $message ) ) {
		$string_backtrace         = wp_json_encode( debug_backtrace() );
		$comes_from_admin_ajax    = stripos( $string_backtrace, 'admin-ajax.php' ) !== false;
		$has_maybe_handle         = stripos( $string_backtrace, 'maybe_handle' ) !== false;
		$comes_from_bg_processing = stripos( $string_backtrace, 'WP_Background_Process' ) !== false || stripos( $string_backtrace, 'WP_Async_Request' ) !== false;
		if ( $comes_from_admin_ajax || ( $has_maybe_handle && $comes_from_bg_processing ) ) {
			// Lawful wp_die().
			call_user_func( $cd_original_handler, $message, $title, $args );
			return;
		}
	}

	[ $message, $title, $parsed_args ] = _wp_die_process_input( $message, $title, $args );

	$message = html_entity_decode( sanitize_text_field( $message ) );
	$title   = html_entity_decode( sanitize_text_field( $title ) );
	array_walk_recursive( $parsed_args, static function ( &$v ) {
		if ( is_scalar( $v ) ) {
			$v = html_entity_decode( sanitize_text_field( $v ) );
		}
	} );

	$error_combined = wp_json_encode( [
		'message' => $message,
		'title'   => $title,
		'args'    => $parsed_args
	] );

	$has_fatal_string    = stripos( $error_combined, 'fatal' ) !== false;
	$has_wp_error_string = stripos( $error_combined, 'has been a critical error on this website' ) !== false;

	if ( $has_fatal_string || $has_wp_error_string ) {
		$is_fatal   = 'Yes';
		$error_type = 'E_USER_ERROR';
	} else {
		$is_fatal   = 'Maybe';
		$error_type = 'E_USER_WARNING';
	}

	cd_add_to_result_file(
		$is_fatal,
		$cd_original_handler,
		$error_type,
		$error_combined,
		'Undefined',
		0,
		get_option( 'cd_activated_alongside', '' ),
		cd_get_debugbacktrace(),
		''
	);

	call_user_func( $cd_original_handler, $message, $title, $args );
}

function cd_db_error_listener() {
	global $wpdb;

	if ( ! $wpdb instanceof wpdb || empty( $wpdb->last_error ) ) {
		return;
	}

	cd_add_to_result_file(
		'Maybe',
		$_SERVER['REQUEST_URI'] ?: 'WP-CLI Plugin Activation',
		'',
		'',
		'',
		'',
		get_option( 'cd_activated_alongside', '' ),
		cd_get_debugbacktrace(),
		$wpdb->last_error
	);
}

$GLOBALS['QIT_FATAL_ERROR_LOGGED'] = false;

// Exception handler
function cd_php_exception_handler(\Throwable $exception) {
	$GLOBALS['QIT_FATAL_ERROR_LOGGED'] = true;

	// Call the custom error handler with exception information
	cd_php_error_handler(
		E_ERROR,
		$exception->getMessage(),
		$exception->getFile(),
		$exception->getLine()
	);
}

// Parse error (shutdown) handler
function cd_php_parse_error_handler() {
	if ( $GLOBALS['QIT_FATAL_ERROR_LOGGED'] ) {
		return;
	}

	// Get the last error that occurred
	$error = error_get_last();

	// Check if it's a fatal error
	$fatal_error_types = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR];
	if (isset($error) && in_array($error['type'], $fatal_error_types)) {
		// Call the custom error handler with error information
		cd_php_error_handler(
			$error['type'],
			$error['message'],
			$error['file'],
			$error['line']
		);
	}
}

/**
 * @param int    $errno The integer that represents a type of error. https://www.php.net/manual/en/errorfunc.constants.php
 * @param string $errstr The error string.
 * @param string $errfile The file where the error was triggered.
 * @param int    $errline The line where the error was triggered.
 *
 * @return void
 */
function cd_php_error_handler( $errno, $errstr, $errfile, $errline ) {
	// Do not log network errors, since we disable external HTTP requests for performance.
	if ( strpos( $errstr, 'WordPress could not establish a secure connection to WordPress.org' ) !== false ) {
		return;
	}

	// Errors that bring PHP to a halt.
	$fatal_error_types = [
		E_ERROR             => 'E_ERROR',
		E_PARSE             => 'E_PARSE',
		E_USER_ERROR        => 'E_USER_ERROR',
		E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
		E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR'
	];

	// Provide friendly-names for the error codes
	$all_error_types = [
		E_ERROR             => "E_ERROR",
		E_WARNING           => "E_WARNING",
		E_PARSE             => "E_PARSE",
		E_NOTICE            => "E_NOTICE",
		E_CORE_ERROR        => "E_CORE_ERROR",
		E_CORE_WARNING      => "E_CORE_WARNING",
		E_COMPILE_ERROR     => "E_COMPILE_ERROR",
		E_COMPILE_WARNING   => "E_COMPILE_WARNING",
		E_USER_ERROR        => "E_USER_ERROR",
		E_USER_WARNING      => "E_USER_WARNING",
		E_USER_NOTICE       => "E_USER_NOTICE",
		E_STRICT            => "E_STRICT",
		E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
		E_DEPRECATED        => "E_DEPRECATED",
		E_USER_DEPRECATED   => "E_USER_DEPRECATED",
		E_ALL               => "E_ALL"
	];

	cd_add_to_result_file(
		isset( $fatal_error_types[ $errno ] ) ? 'Yes' : 'No',
		$_SERVER['REQUEST_URI'] ?: 'WP-CLI Plugin Activation',
		$all_error_types[ $errno ],
		$errstr,
		$errfile,
		$errline,
		get_option( 'cd_activated_alongside', '' ),
		cd_get_debugbacktrace(),
		''
	);
}

function cd_add_to_result_file(
	$is_fatal,
	$context,
	$error_type,
	$error_message,
	$error_file,
	$error_line,
	$activated_alongside,
	$backtrace,
	$db_error
) {
	// Is this error being triggered by WooCommerce Core?
	if ( stripos( $error_file, '/wp-content/plugins/woocommerce/' ) !== false ) {
		// Is WooCommerce Core being tested?
		if ( ! in_array( get_option( 'qit_main_sut_slug' ), [ 'woocommerce', 'wporg-woocommerce' ], true ) ) {
			return;
		}
	}

	// If the error happened in a plugin.
	if ( stripos( $error_file, '/wp-content/plugins/' ) !== false ) {
		// Reduce path to only inside the "plugins" folder.
		// Eg: /var/www/html/wp-content/plugins/sut/sut.php => sut/sut.php
		$error_file = substr( $error_file, strpos( $error_file, '/wp-content/plugins/' ) + strlen( '/wp-content/plugins/' ) );
	}

	$error_message = cd_filter_sensitive_keywords( (string) $error_message );
	$error_file    = cd_filter_sensitive_keywords( (string) $error_file );
	$db_error      = cd_filter_sensitive_keywords( (string) $db_error );

	// Prevent logging errors that happen during bootstrap, such as ones thrown by WP-CLI itself.
	if ( empty( get_option( 'cd_enable_error_logging' ) ) ) {
		return;
	}

	// Ignore some PHP 8 errors.
	if ( PHP_MAJOR_VERSION === 8 ) {
		/*
		 * This file from WordPress Core might issue warnings on PHP 8,
		 * and we are not interested in logging those.
		 * @see wp-includes/Requests/Utility/CaseInsensitiveDictionary.php
		 */
		if ( stripos( $error_file, 'CaseInsensitiveDictionary.php' ) !== false ) {
			if ( stripos( $error_message, 'ReturnTypeWillChange' ) !== false ) {
				return;
			}
		}

		// Another WordPress Core file.
		if ( stripos( $error_file, 'Cookie/Jar.php' ) !== false ) {
			if ( stripos( $error_message, 'ReturnTypeWillChange' ) !== false ) {
				return;
			}
		}

		// WP-CLI file.
		if ( stripos( $error_file, 'Transport/cURL.php' ) !== false ) {
			if ( stripos( $error_message, 'http_build_query(): Passing null to parameter #2 ($numeric_prefix) of type string is deprecated' ) !== false ) {
				return;
			}
		}

		// WP-CLI file.
		if ( stripos( $error_file, 'Theme_Command.php' ) !== false ) {
			if ( stripos( $error_message, 'Creation of dynamic property Theme_Command::$fetcher is deprecated' ) !== false ) {
				return;
			}
		}

		/*
		 * Ignore PHP 8.2 notice that comes from WP CLI itself.
		 * Todo: Remove this once WP CLI 2.8.0 is released
		 * @see https://github.com/wp-cli/wp-cli/pull/5707
		 */
		if ( stripos( $error_file, 'Dispatcher/CompositeCommand.php' ) !== false ) {
			if ( stripos( $error_message, 'Creation of dynamic property WP_CLI' ) !== false ) {
				return;
			}
		}
	}

	try {
		$contents = cd_get_results_file();

		$results = json_decode( trim( $contents ), true, JSON_THROW_ON_ERROR );

		if ( ! is_array( $results ) ) {
			throw new RuntimeException( sprintf( "Contents is not a valid JSON. Contents type: %s Lenght: %s Raw: %s", gettype( $contents ), strlen( $contents ), $contents ) );
		}

		// Initialize "results_overview" array if needed.
		if ( ! array_key_exists( 'results_overview', $results ) ) {
			$results['results_overview'] = [
				'total_extensions'       => 0,
				'extensions_with_errors' => [],
				'error_totals'           => [
					'fatal'   => 0,
					'notice'  => 0,
					'warning' => 0,
				],
				'summary'                => '',
			];
		}

		/*
		 * Set the total extensions separately from the initialization,
		 * which might have been triggered by WP CLI itself, in which case
		 * the option would still not be available.
		 */
		if ( empty( $results['results_overview']['total_extensions'] ) ) {
			$results['results_overview']['total_extensions'] = get_option( 'cd_qty_extensions_being_tested', 0 );
		}

		// Initialize "activated_alongside" array if needed.
		if ( ! array_key_exists( $activated_alongside, $results['results_overview']['extensions_with_errors'] ) ) {
			$results['results_overview']['extensions_with_errors'][ $activated_alongside ] = [];
		}

		// Initialize "context" array if needed. At the end, we have a count of failures per extension grouped by context.
		if ( ! array_key_exists( $context, $results['results_overview']['extensions_with_errors'][ $activated_alongside ] ) ) {
			$results['results_overview']['extensions_with_errors'][ $activated_alongside ][ $context ] = 1;
		} else {
			$results['results_overview']['extensions_with_errors'][ $activated_alongside ][ $context ] ++;
		}

		// Initialize an error counter for convenience.
		if ( ! array_key_exists( 'error_count', $results['results_overview'] ) ) {
			$results['results_overview']['error_count'] = 1;
		} else {
			$results['results_overview']['error_count'] ++;
		}

		// Initialize an extension that has errors counter for convenience.
		$results['results_overview']['count_extensions_with_errors'] = count( $results['results_overview']['extensions_with_errors'] );

		/**
		 * When $error_file points to the STDERR logger file, this means we are listening
		 * for the error "from the outside", and we don't have the context of the error,
		 * therefore, the error file is empty.
		 *
		 * This can be improved if we stop using WP CLI to activate the plugin under test.
		 *
		 * @link https://github.com/Automattic/compatibility-dashboard/pull/299#issuecomment-1376497843
		 */
		if ( stripos( $error_file, 'compatibility-stderr-logger.php' ) !== false ) {
			$error_file = '-';
			$error_line = '-';
		}

		$results[] = [
			'activated_alongside' => $activated_alongside,
			'context'             => $context,
			'is_fatal'            => $is_fatal,
			'error_type'          => $error_type,
			'error_message'       => $error_message,
			'error_file'          => $error_file,
			'error_line'          => $error_line,
			'backtrace'           => $backtrace,
			'db_error'            => $db_error,
		];

		// Build Error Summary
		$results['results_overview']['error_totals'] = cd_update_error_totals( $error_type, $results['results_overview']['error_totals'] );
		$results['results_overview']['summary']      = sprintf( '%d Errors Detected. (%d Fatal, %d Warnings, %d Notices)',
			$results['results_overview']['error_count'],
			$results['results_overview']['error_totals']['fatal'],
			$results['results_overview']['error_totals']['warning'],
			$results['results_overview']['error_totals']['notice'],
		);

		// The backtrace in this extension causes the JSON to be invalid. Skipping it for now to come back to it later.
		if ( $activated_alongside === 'social-proof-for-woocommerce' ) {
			echo "Debugging social-proof-for-woocommerce\n";
			var_dump( $backtrace );
			echo "Debugging social-proof-for-woocommerce end.\n";

			return;
		}

		cd_write_new_results_file( wp_json_encode( $results, JSON_THROW_ON_ERROR ) );
	} catch ( Exception $e ) {
		echo "\nAn error occurred with the Compatibility Results.\n";
		echo $e->getMessage() . "\n";
		die( 123 );
	}
}

function cd_get_results_file(): string {
	$results = file_get_contents( __DIR__ . '/compatibility-results.txt' );

	if ( $results === false ) {
		throw new RuntimeException( 'Could not read CD error log.' );
	}

	return $results;
}

function cd_write_new_results_file( string $data ) {
	$written = file_put_contents( __DIR__ . '/compatibility-results.txt', $data );

	if ( $written === false ) {
		throw new RuntimeException( 'Could not write to CD error log.' );
	}
}

function cd_update_error_totals( string $error_type, array $error_totals ): array {
	$error_type_mappings = [
		'fatal' => [
			'E_ERROR',
			'E_PARSE',
			'E_CORE_ERROR',
			'E_COMPILE_ERROR',
			'E_USER_ERROR',
			'E_RECOVERABLE_ERROR',
		],
		'warning' => [
			'E_WARNING',
			'E_USER_WARNING',
			'E_CORE_WARNING',
			'E_COMPILE_WARNING',
		],
		'notice' => [
			'E_NOTICE',
			'E_USER_NOTICE',
			'E_DEPRECATED',
		]
	];

	$error_keys = array_keys( $error_type_mappings );

	foreach ( $error_keys as $key ) {
		if ( in_array( $error_type, $error_type_mappings[ $key ] ) ) {
			$error_totals[ $key ] += 1;

			if ( array_key_exists( $error_type, $error_totals ) ) {
				$error_totals[ $error_type ] += 1;
			} else {
				$error_totals[ $error_type ] = 1;
			}

			break;
		}
	}

	return $error_totals;
}

function cd_get_debugbacktrace(): array {
	/*
	 * Include the last 5 things that happened before the error.
	 * 5 should cover the vast majority of errors, while not being
	 * big enough to pollute the backtrace with internal CD code.
	 */
	$debug_backtrace_size = 5;

	// Do not include error handling functions in the backtrace.
	$ignored_backtrace_functions = [
		'cd_php_error_handler',
		'cd_db_error_listener',
		'cd_get_debugbacktrace',
		'cd_die_handler'
	];

	$debug_backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $debug_backtrace_size + count( $ignored_backtrace_functions ) );

	$to_remove = [];

	foreach ( $debug_backtrace as $i => $current_backtrace ) {
		if ( is_array( $current_backtrace ) && array_key_exists( 'function', $current_backtrace ) ) {
			if ( in_array( $current_backtrace['function'], $ignored_backtrace_functions ) ) {
				$to_remove[] = $i;
			}
		}
	}

	foreach ( $to_remove as $i ) {
		unset( $debug_backtrace[ $i ] );
	}

	$debug_backtrace = array_values( $debug_backtrace );

	$debug_backtrace = json_decode( cd_filter_sensitive_keywords( wp_json_encode( $debug_backtrace ) ), true ) ?: [];

	// Remove very big backtrace entries.
	foreach ( $debug_backtrace as $k => &$v ) {
		if ( strlen( wp_json_encode( $v ) ) > 1024 ) {
			$v = '[Backtrace removed as it exceeds 1024 characters]';
		}
	}

	// Limit the size of the backtrace to 32kb.
	while ( strlen( wp_json_encode( $debug_backtrace ) ) > 32000 ) {
		array_pop( $debug_backtrace );
	}

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		echo "An error occurred encoding the JSON.\n";
		echo json_last_error_msg() . PHP_EOL;
	}

	return $debug_backtrace;
}

function cd_filter_sensitive_keywords( string $input ): string {
	$sensitive_keywords = [
		'all-plugins',
		'product-packages'
	];

	foreach ( $sensitive_keywords as $s ) {
		// This replaces each sensitive word with ***.
		// We considered matching * with the size of the original string,
		// but this would leak the size of the original string we were hiding.
		$input = str_ireplace( $s, '***', $input );
	}

	return $input;
}