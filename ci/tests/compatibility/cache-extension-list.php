<?php

require_once __DIR__ . '/ci-runner.php';

$response = CI_Runner::cd_manager_request( 'get_extensions', [
	'hosted_at' => getenv( 'HOSTED_AT' ) ?: '',
] );

$parsed = json_decode( $response, true );

if ( is_null( $parsed ) ) {
	echo 'Invalid JSON';
	die( 1 );
}

// Sort so that MD5 is predictable regardless of order of items.
ksort( $parsed );

echo json_encode( $parsed );