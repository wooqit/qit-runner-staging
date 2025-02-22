<?php

$env = getenv();

// Required env vars from your workflow
$required_envs = [
	'SUT_TYPE',         // e.g. "plugins" or "themes"
	'GITHUB_WORKSPACE', // base path
	'PHPSTAN_LEVEL',    // numeric or default
	'GITHUB_WORKSPACE',
];
foreach ( $required_envs as $required ) {
	if ( ! isset( $env[ $required ] ) ) {
		echo "Missing required env: $required\n";
		exit( 1 );
	}
}

$root         = rtrim( $env['GITHUB_WORKSPACE'], '/\\' );
$sut_type     = $env['SUT_TYPE'];
$phpstanLevel = is_numeric( $env['PHPSTAN_LEVEL'] ) ? $env['PHPSTAN_LEVEL'] : 2;
$wooRealPath = $root . '/ci/tmp/wordpress/wp-content/plugins/woocommerce';

// The SUT slug is given as a CLI argument: php generate-phpstan.php <SUT_SLUG>
if ( ! isset( $argv[1] ) ) {
	echo "Usage: php generate-phpstan.php <SUT_SLUG>\n";
	exit( 100 );
}
$sut_slug = $argv[1];

// We also read PLUGINS_JSON (the full list of “extensions”) from env.
// This is so we can exclude other extension code from analysis.
$plugins_json  = isset( $env['PLUGINS_JSON'] ) ? $env['PLUGINS_JSON'] : '[]';
$plugins_array = @json_decode( $plugins_json, true );
if ( ! is_array( $plugins_array ) ) {
	// If not provided or invalid, we default to an empty list
	$plugins_array = [];
}

// The location where the SUT and other “extensions” are stored
$extensions_dir = $root . '/ci/' . $sut_type . 's';
if ( ! is_dir( $extensions_dir ) ) {
	echo "Extensions directory not found: $extensions_dir\n";
	exit( 1 );
}

// Helper to produce a NEON array with indentation
$make_neon_array = function ( int $indentation, array $items ) {
	if ( empty( $items ) ) {
		return str_repeat( ' ', $indentation ) . '[]';
	}

	return str_repeat( ' ', $indentation ) . '- ' . implode(
			PHP_EOL . str_repeat( ' ', $indentation ) . '- ',
			$items
		);
};

/**
 * 1. Analyze only the SUT's real code:
 */
$paths = $make_neon_array( 4, [
	"$extensions_dir/$sut_slug", // Only the SUT is analyzed
] );

/**
 * 2. Exclude real code of other extensions + SUT vendor/ from analysis
 */
$exclude_paths_raw = [
	// Exclude vendor, vendor-prefixed, etc. inside the SUT
	"$extensions_dir/$sut_slug/vendor/*",
	"$extensions_dir/$sut_slug/vendor-prefixed/*",
	"$extensions_dir/$sut_slug/vendor-scoped/*",
];

// If real WooCommerce folder exists, exclude it, as we will use the stubs.
if ( is_dir( $wooRealPath ) ) {
	$exclude_paths_raw[] = $wooRealPath . '/*';
}

// For each other extension, exclude its real code:
foreach ( $plugins_array as $ext ) {
	if ( empty( $ext['slug'] ) || $ext['slug'] === $sut_slug ) {
		continue; // skip the SUT or invalid
	}
	$exclude_paths_raw[] = "$extensions_dir/{$ext['slug']}/*";
}
$exclude_paths = $make_neon_array( 8, $exclude_paths_raw );

/**
 * 3. We want to “scan” stubs for the SUT (in case of hook-based definitions)
 *    and stubs for other extensions. So we add them to scanDirectories.
 */
$sut_stubs_dir = __DIR__ . '/stubs/sut_stubs';
$dep_stubs_dir = __DIR__ . '/stubs/dep_stubs'; // which may contain subfolders
$scan_dirs_raw = [];

// If the stubs folder for the SUT exists, add it:
if ( is_dir( $sut_stubs_dir ) ) {
	$scan_dirs_raw[] = $sut_stubs_dir;
}
// If the dependency stubs folder exists, add it:
if ( is_dir( $dep_stubs_dir ) ) {
	$scan_dirs_raw[] = $dep_stubs_dir;
}
$scan_directories = $make_neon_array( 4, $scan_dirs_raw );

/**
 * 4. We rely on official WP stubs for WordPress definitions.
 *    (No scanning of actual /ci/tmp/wordpress.)
 */
$scan_files = $make_neon_array( 4, [
	'%rootDir%/../../php-stubs/wordpress-stubs/wordpress-stubs.php',
	'%rootDir%/../../php-stubs/wp-cli-stubs/wp-cli-stubs.php',
	'%rootDir%/../../php-stubs/wp-cli-stubs/wp-cli-commands-stubs.php',
	'%rootDir%/../../php-stubs/wp-cli-stubs/wp-cli-i18n-stubs.php',
	'%rootDir%/../../php-stubs/wp-cli-stubs/wp-cli-tools-stubs.php',
] );

/**
 * 5. If the SUT itself has extra phpstan config (phpstan.neon, etc.), include them
 *    so it can define custom rules or parameters.
 */
$files_to_include = [];
if ( file_exists( "$extensions_dir/$sut_slug/phpstan.neon" ) ) {
	$files_to_include[] = "$extensions_dir/$sut_slug/phpstan.neon";
}
if ( file_exists( "$extensions_dir/$sut_slug/phpstan.neon.dist" ) ) {
	$files_to_include[] = "$extensions_dir/$sut_slug/phpstan.neon.dist";
}
if ( file_exists( "$extensions_dir/$sut_slug/phpstan.dist.neon" ) ) {
	$files_to_include[] = "$extensions_dir/$sut_slug/phpstan.dist.neon";
}
$includes_section = '';
if ( ! empty( $files_to_include ) ) {
	$files_to_include = $make_neon_array( 4, $files_to_include );
	$includes_section = <<<INCLUDES
includes:
$files_to_include
INCLUDES;
}

/**
 * 6. Finally, build the phpstan.neon config string
 */
$phpstan = <<<NEON
includes:
  - vendor/szepeviktor/phpstan-wordpress/extension.neon

$includes_section

parameters:
  level: $phpstanLevel

  # Analyze only real SUT code
  paths:
$paths

  excludePaths:
    analyse:
$exclude_paths

  # Just scan stubs for SUT & dependencies
  scanDirectories:
$scan_directories

  # WordPress stubs
  scanFiles:
$scan_files

  parallel:
    jobSize: 10
    maximumNumberOfProcesses: 32
    minimumNumberOfJobsPerProcess: 2

  inferPrivatePropertyTypeFromConstructor: true
  reportUnmatchedIgnoredErrors: false
NEON;

// 7. Write the config
file_put_contents( __DIR__ . '/phpstan.neon', $phpstan );

echo "[INFO] Generated phpstan.neon with level=$phpstanLevel.\n";
exit( 0 );