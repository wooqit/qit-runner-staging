<?php

$env = getenv();

$required_envs = [
	'PHPCS_RESULT_FILE',
	'GITHUB_WORKSPACE',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

$normalized_result_file = $env['GITHUB_WORKSPACE'] . '/ci/tests/php-compatibility/normalized_result.json';
$phpcs_result_file      = rtrim( $env['GITHUB_WORKSPACE'], '/' ) . '/' . $env['PHPCS_RESULT_FILE'];

$normalized_result = [
	'tool' => [
		'phpcs'   => [],
	]
];

if ( file_exists( $phpcs_result_file ) ) {
	$phpcs_result = json_decode( file_get_contents( $phpcs_result_file ), true );

	if ( ! empty( $phpcs_result ) ) {
		$normalized_result['tool']['phpcs'] = $phpcs_result;
	}
}

$written = file_put_contents( $normalized_result_file, json_encode( $normalized_result ) );

if ( ! $written ) {
	echo "Failed to write normalized result file\n";
	die( 1 );
}