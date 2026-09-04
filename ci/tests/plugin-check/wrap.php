<?php

$env = getenv();

$required_envs = [
	'RESULT_FILE',
	'OUTPUT_FILE',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

$result_file = $env['RESULT_FILE'];
$output_file = $env['OUTPUT_FILE'];
$written = false;

if ( file_exists( $result_file ) ) {
	$result = file_get_contents( $result_file );

	if ( ! empty( $result ) ) {
		$output = json_encode( [ 'output' => $result ] );

		$written = file_put_contents( $output_file, $output );
	}
}

if ( ! $written ) {
	echo "Failed to write normalized result file\n";
	die( 1 );
}
