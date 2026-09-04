<?php


$env = getenv();

$required_envs = [
    'PLUGINS_JSON',
    'SUT_WOO_ID',
    'PLUGIN_SLUG',
    'PLUGIN_TYPE',
    'WORDPRESS_VERSION',
    'WOOCOMMERCE_VERSION',
    'PHP_VERSION',
    'TEST_RUN_ID',
    'TEST_VARIATION',
];

foreach ( $required_envs as $required_env ) {
    if ( ! isset( $env[ $required_env ] ) ) {
        echo "Missing required env: $required_env\n";
        die( 1 );
    }
}

$plugins        = json_decode( trim( trim( getenv( 'PLUGINS_JSON' ) ), "'" ), true );
$wp             = getenv( 'WORDPRESS_VERSION' );
$woocommerce    = getenv( 'WOOCOMMERCE_VERSION' );
$woo_id         = getenv( 'SUT_WOO_ID' );
$slug           = getenv( 'PLUGIN_SLUG' );
$type           = getenv( 'PLUGIN_TYPE' ) . 's';
$test_run_id    = getenv( 'TEST_RUN_ID' );
$php_version    = getenv( 'PHP_VERSION' );
$test_variation = getenv( 'TEST_VARIATION' );
$test_packages  = json_decode( getenv( 'TEST_PACKAGES' ) ?: '[]', true ) ?: [];
$base_command = "QIT_NO_PULL=1 QIT_RESULTS_DIR='./results' QIT_TEST_RUN_ID=$test_run_id ./qit run:activation $woo_id --source ../../$type/$slug --wp $wp --woo $woocommerce --php_version $php_version";

// The activation package follows the WooCommerce version, and the Manager has
// already picked the one covering this run. Hand that over rather than letting
// the CLI resolve it again: the bundled phar may predate the resolver, and a
// run that executes a different package from the one the Manager recorded and
// hashed is the mismatch this is meant to prevent.
foreach ( $test_packages as $test_package ) {
    if ( is_string( $test_package ) && $test_package !== '' ) {
        $base_command .= ' --test-package=' . escapeshellarg( $test_package );
    }
}

foreach ( $plugins as $plugin ) {

    if ( $plugin['slug'] === $slug ) {
        continue;
    }

    $base_command .= ' -p ' . escapeshellarg( $plugin['slug'] );
}

// Add verbose flag.
$base_command .= ' -v';

if ( ! empty( $test_variation ) ) {
    $base_command .= ' --passthrough_target=woocommerce/activation -- --grep=' . escapeshellarg( '@' . $test_variation );
}

echo "Running command: $base_command\n";

passthru( $base_command, $return_var );

// Check for success or failure
if ( $return_var === 0 ) {
    echo "Command executed successfully!\n";
} else {
    echo "Command failed with error code: $return_var\n";
}

die( $return_var );
