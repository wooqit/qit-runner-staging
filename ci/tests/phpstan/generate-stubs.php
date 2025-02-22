#!/usr/bin/env php
<?php

error_reporting( E_ALL );

// 0) Grab GITHUB_WORKSPACE from env
$root = rtrim( getenv( 'GITHUB_WORKSPACE' ), '/\\' );
if ( ! $root ) {
	fwrite( STDERR, "Missing GITHUB_WORKSPACE env.\n" );
	exit( 1 );
}

// 1) Read required environment variables
$sut_slug        = getenv( 'SUT_SLUG' );
$sut_type        = getenv( 'SUT_TYPE' );  // "plugins" or "themes"
$extensions_json = getenv( 'EXTENSIONS_JSON' );

if ( ! $sut_slug || ! $sut_type || ! $extensions_json ) {
	fwrite( STDERR, "Missing one of SUT_SLUG, SUT_TYPE, or EXTENSIONS_JSON.\n" );
	exit( 1 );
}

// 2) Determine real folder where plugin_or_theme code is stored (e.g. ci/plugins or ci/themes)
$base_dir = realpath( __DIR__ . "/../../../ci/{$sut_type}s" );
if ( ! $base_dir ) {
	fwrite( STDERR, "Could not find base directory for {$sut_type}s at ../../../ci/{$sut_type}s\n" );
	exit( 1 );
}

// 3) Verify that `stubz.php` exists in the cloned Stubz folder (adjust path if needed)
$stubz_path = realpath( __DIR__ . "/stubz/stubz.php" );
if ( ! $stubz_path || ! file_exists( $stubz_path ) ) {
	fwrite( STDERR, "[ERROR] stubz.php not found: $stubz_path\n" );
	fwrite( STDERR, "Make sure you cloned the stubz repo and that stubz.php is present.\n" );
	exit( 1 );
}

// 4) Stub output directories
$sut_stubs_dir  = __DIR__ . '/stubs/sut_stubs';
$dep_stubs_base = __DIR__ . '/stubs/dep_stubs';

// Create the stub directories if they don't exist
@mkdir( $sut_stubs_dir, 0777, true );
@mkdir( $dep_stubs_base, 0777, true );

// 5) Parse the JSON array of extensions
$extensions_array = json_decode( $extensions_json, true );
if ( ! is_array( $extensions_array ) ) {
	fwrite( STDERR, "EXTENSIONS_JSON is not a valid JSON array.\n" );
	exit( 1 );
}

$woo_tmp_path = $root . '/ci/tmp/wordpress/wp-content/plugins/woocommerce';
// 3) If WooCommerce is present, generate stubs
if (is_dir($woo_tmp_path)) {
	$dep_stubs_base = __DIR__ . '/stubs/dep_stubs';
	@mkdir("$dep_stubs_base/woocommerce", 0777, true);

	echo "[INFO] Found WooCommerce in $woo_tmp_path. Generating stubs...\n";
	generate_stubs_dir(
		$woo_tmp_path,
		"$dep_stubs_base/woocommerce",
		$stubz_path // your stubz.php path
	);
}

/**
 * Helper function to call `stubz` from stubz.php, creating a mirror of the source directory.
 *
 * Example: php stubz.php /original-plugin /generated-plugin
 */
function generate_stubs_dir( $source_dir, $dest_dir, $stubz_path ) {
	$source_esc = escapeshellarg( $source_dir );
	$dest_esc   = escapeshellarg( $dest_dir );

	// expects: stubz.php <source> <destination>
	$cmd = "php " . escapeshellarg($stubz_path) . " " . $source_esc . " " . $dest_esc;

	echo "[INFO] Generating stubs:\n  - Source: $source_dir\n  - Destination: $dest_dir\n";
	passthru( $cmd, $exit_code );
	if ( $exit_code !== 0 ) {
		fwrite( STDERR, "[WARN] stubz failed with exit code $exit_code.\n" );
	}
}

// 6) Generate stubs for additional extensions (NOT the SUT)
echo "[INFO] Generating stubs for additional extensions...\n";
foreach ( $extensions_array as $extension ) {
	if ( empty( $extension['slug'] ) ) {
		continue;
	}

	// Skip the SUT
	if ( $extension['slug'] === $sut_slug ) {
		continue;
	}

	$extension_path = "$base_dir/{$extension['slug']}";
	if ( ! is_dir( $extension_path ) ) {
		echo "[WARN] Could not find extension directory: $extension_path. Skipping.\n";
		continue;
	}

	// We'll generate into dep_stubs_base/extension-slug
	$extension_out_dir = "$dep_stubs_base/{$extension['slug']}";
	@mkdir( $extension_out_dir, 0777, true );

	generate_stubs_dir( $extension_path, $extension_out_dir, $stubz_path );
}

// 7) Generate stubs for the SUT itself
$sut_path = "$base_dir/$sut_slug";
if ( ! is_dir( $sut_path ) ) {
	fwrite( STDERR, "[ERROR] SUT directory not found: $sut_path\n" );
	exit( 1 );
}

echo "[INFO] Generating stubs for the SUT: $sut_path\n";

// We'll generate into sut_stubs_dir/sut_slug
$sut_out_dir = "$sut_stubs_dir/$sut_slug";
@mkdir( $sut_out_dir, 0777, true );

generate_stubs_dir( $sut_path, $sut_out_dir, $stubz_path );

echo "[INFO] Stub generation complete.\n";
exit( 0 );