<?php

$env = getenv();

$required_envs = [
	'CD_PLUGIN_DIR',
	'SUT_SLUG',
	'MIN_PHP_VERSION',
	'MAX_PHP_VERSION',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

if ( isset( $env['GITHUB_WORKSPACE'] ) ) {
	$plugin_dir = rtrim( $env['GITHUB_WORKSPACE'], '/' ) . '/' . $env['CD_PLUGIN_DIR'];
} else {
	$plugin_dir = $env['CD_PLUGIN_DIR'];
}

function set_php_range( $xml, $min_php_version, $max_php_version ) {
	// Find the config elements with a testVersion attribute
	$config_element = $xml->xpath('//config[@name="testVersion"]');

	// Loop through the found config elements and update the attribute value
	foreach ( $config_element as $config ) {
		$config[ 'value' ] = $min_php_version . '-' . $max_php_version;
	}
}

// Load the first XML file
$xml = simplexml_load_file(__DIR__ . '/.phpcs.xml' );

set_php_range( $xml, $env['MIN_PHP_VERSION'], $env['MAX_PHP_VERSION'] );

// Save the merged XML to a file
$path = __DIR__ . '/phpcs.xml';
$xml->asXML( $path );

if ( isset( $env['UNIT_TESTS'] ) ) {
	return [
		'phpcs_config' => $xml,
	];
} else {

	// Format the XML file
	$dom = new DOMDocument();
	$dom->preserveWhiteSpace = false;
	$dom->formatOutput       = true;
	$dom->load( $path );
	$dom->save( $path );

	echo 'XML files merged successfully and saved to: ' . $path . PHP_EOL;
}

