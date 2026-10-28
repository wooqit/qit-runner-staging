<?php

/**
 * Returns the QIT CLI options that load the PHP extensions the Manager resolved for this run.
 *
 * The bundled CLI does not apply the Manager's php_extensions itself, so a product that needs
 * e.g. SOAP is tested without it unless the runner forwards them.
 *
 * @return string Options to append before any passthrough separator, or '' when none are required.
 */
function qit_cli_php_extension_options() {
	$php_extensions_json = trim( (string) getenv( 'PHP_EXTENSIONS' ) );

	if ( $php_extensions_json === '' ) {
		return '';
	}

	// Objects stay objects, so a JSON object is not mistaken for a list.
	$php_extensions = json_decode( $php_extensions_json );

	if ( json_last_error() === JSON_ERROR_NONE && ( $php_extensions === null || $php_extensions === [] ) ) {
		return '';
	}

	$is_list_of_names = is_array( $php_extensions ) && array_values( $php_extensions ) === $php_extensions;

	if ( $is_list_of_names ) {
		foreach ( $php_extensions as $php_extension ) {
			if ( ! is_string( $php_extension ) || trim( $php_extension ) === '' ) {
				$is_list_of_names = false;
			}
		}
	}

	// Running without a required extension is the failure this guards against, so never ignore bad input.
	if ( ! $is_list_of_names ) {
		echo "Invalid PHP_EXTENSIONS, expected a JSON list of extension names: $php_extensions_json\n";
		die( 1 );
	}

	$options = '';

	foreach ( $php_extensions as $php_extension ) {
		$options .= ' --php_extension=' . escapeshellarg( $php_extension );
	}

	return $options;
}
