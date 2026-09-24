<?php
$env = getenv();

// This must exist
$required_envs = [
	'SUT_TYPE',
	'GITHUB_WORKSPACE',
	'PHPSTAN_LEVEL',
	'PHP_VERSION',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

$root = rtrim( $env['GITHUB_WORKSPACE'], '/' );

try {
	if ( ! isset( $argv[1] ) ) {
		throw new RuntimeException( 'Run this as php generate-phpstan.php SUT_SLUG', 100 );
	}

	$sut_slug    = $argv[1];                               // e.g. woocommerce-gateway-stripe
	$sut_type    = $env['SUT_TYPE'];                       // e.g. plugin or theme
	$plugins_dir = $root . '/ci/' . $sut_type . 's';       // e.g. /.../ci/plugins
	$config_path = isset( $env['PHPSTAN_CONFIG_OUTPUT'] ) && $env['PHPSTAN_CONFIG_OUTPUT'] !== ''
		? $env['PHPSTAN_CONFIG_OUTPUT']
		: __DIR__ . '/phpstan.neon';
	$bootstrap_path = isset( $env['PHPSTAN_BOOTSTRAP_OUTPUT'] ) && $env['PHPSTAN_BOOTSTRAP_OUTPUT'] !== ''
		? $env['PHPSTAN_BOOTSTRAP_OUTPUT']
		: dirname( $config_path ) . '/qit-static-analysis-bootstrap.php';

	$dependencies = [];

	// Determine PHPStan level
	if ( isset( $env['PHPSTAN_LEVEL'] ) && is_numeric( $env['PHPSTAN_LEVEL'] ) ) {
		$level = (int) $env['PHPSTAN_LEVEL'];
	} else {
		$level = 2;
	}

	if ( preg_match( '/^(\d+)\.(\d+)/', $env['PHP_VERSION'], $php_version_matches ) !== 1 ) {
		throw new RuntimeException( sprintf( 'Invalid PHP_VERSION: %s', $env['PHP_VERSION'] ), 101 );
	}
	$php_version_id = ( (int) $php_version_matches[1] * 10000 ) + ( (int) $php_version_matches[2] * 100 );

	// Collect additional plugin/theme folders (besides the SUT)
	$it = new DirectoryIterator( $plugins_dir );
	while ( $it->valid() ) {
		if (
			$it->current()->isDir() &&
			! $it->current()->isDot() &&
			$it->current()->getBasename() !== $sut_slug &&
			substr( $it->current()->getBasename(), 0, 1 ) !== '_'
		) {
			$dependencies[] = $it->current()->getPathname();
		}
		$it->next();
	}

	// Helper to create NEON array blocks. $item_suffix is appended to every entry
	// (e.g. ' (?)' to mark excludePaths optional so a missing path never aborts the run).
	$make_neon_array = function ( $indentation, $array, $item_suffix = '' ) {
		return str_repeat( ' ', $indentation ) . '- ' . implode(
				$item_suffix . PHP_EOL . str_repeat( ' ', $indentation ) . '- ',
				$array
			) . $item_suffix;
	};

	// We'll actually parse $paths for the SUT code to *analyze*
	$paths = $make_neon_array( 4, [
		"$plugins_dir/$sut_slug",
	] );

	// We'll keep a raw array for scanning, then convert it to NEON below.
	$scan_directories_array = [
		$root . '/ci/tmp/wordpress',  // Always scan WP core
	];

	$stubs_base = $root . '/ci/stubs/' . $sut_type . 's';
	if ( file_exists( $stubs_base ) && is_dir( $stubs_base ) ) {
		$scan_directories_array[] = $stubs_base;
	}

	// Folders we *exclude* from analysis inside the SUT
	$exclude_paths = $make_neon_array( 8, [
		"$plugins_dir/$sut_slug/vendor/*",
		"$plugins_dir/$sut_slug/vendor-prefixed/*",
		"$plugins_dir/$sut_slug/vendor-scoped/*",
		"$plugins_dir/$sut_slug/generated/*",
		"$plugins_dir/$sut_slug/lib-3rd-party/*",
	] );

	// If the SUT has extra .neon files, we can "include" them
	$files_to_include = [
		$root . '/ci/tests/phpstan/vendor/szepeviktor/phpstan-wordpress/extension.neon',
	];

	// In compatibility-regression mode we deliberately do NOT include the SUT's own PHPStan
	// config. Many Woo-owned plugins pin php-stubs/woocommerce-stubs (a fixed WooCommerce API)
	// via their scanFiles/bootstrapFiles. If those stubs were loaded, WC symbols would resolve
	// from the pinned stub set regardless of which real WC version is unzipped between the two
	// passes — collapsing the baseline/target diff to zero and silently passing every run. The
	// swapped real WC source in scanDirectories must be the ONLY provider of WC symbols.
	// Additive + opt-in: when this env is unset (the default for the partner phpstan test),
	// behavior is byte-identical to before.
	$regression_mode = isset( $env['PHPSTAN_REGRESSION_MODE'] ) && filter_var( $env['PHPSTAN_REGRESSION_MODE'], FILTER_VALIDATE_BOOLEAN );

	if ( ! $regression_mode ) {
		if ( file_exists( "$plugins_dir/$sut_slug/phpstan.neon" ) ) {
			$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.neon";
		}
		if ( file_exists( "$plugins_dir/$sut_slug/phpstan.neon.dist" ) ) {
			$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.neon.dist";
		}
		if ( file_exists( "$plugins_dir/$sut_slug/phpstan.dist.neon" ) ) {
			$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.dist.neon";
		}
	}

	$includes_section = '';
	if ( ! empty( $files_to_include ) ) {
		$files_to_include = $make_neon_array( 2, $files_to_include );
		$includes_section = <<<INCLUDES
includes:
$files_to_include
INCLUDES;
	}

	$ignore_errors = '';
	if ( $level === 0 ) {
		// PHPStan marks return.missing as non-ignorable, so configuring it here creates a
		// top-level error. PhpstanResultDiff filters that identifier for level-zero sweeps.
		$ignore_errors = <<<EOD
  ignoreErrors:
    - identifier: missingType.return
    - identifier: return.void
    - identifier: arguments.count
EOD;
	}

	// Per-plugin files to exclude from BOTH analysis and symbol scanning — workarounds for
	// files that break PHPStan for a specific extension. Keyed by SUT slug so this is a
	// general, extensible mechanism for ANY Woo plugin, not a one-off for Stripe. Add a slug
	// and its relative paths here to exclude files for any other extension.
	//
	// Entries are emitted as optional ( ' (?)' ) on purpose: when analysing OLDER plugin
	// versions (e.g. a Woo-core-vs-back-catalogue compatibility sweep) a file may not exist
	// in every version, and a missing *literal* excludePath hard-fails PHPStan
	// ("Invalid entry in excludePaths") before any analysis runs.
	$per_plugin_excluded_files = [
		'woocommerce-gateway-stripe' => [
			'includes/payment-methods/class-wc-stripe-payment-request.php',
			'includes/payment-methods/class-wc-stripe-express-checkout-helper.php',
		],
	];

	$analyse_and_scan_excludes = [];
	foreach ( $per_plugin_excluded_files[ $sut_slug ] ?? [] as $relative_path ) {
		$analyse_and_scan_excludes[] = "$plugins_dir/$sut_slug/$relative_path";
	}

	$exclude_analyse_and_scan_paths = empty( $analyse_and_scan_excludes )
		? ''
		: $make_neon_array( 8, $analyse_and_scan_excludes, ' (?)' );

	// Convert $scan_directories_array -> NEON
	$scan_directories = $make_neon_array( 4, $scan_directories_array );

	$scan_files_array = [
		$root . '/ci/tests/phpstan/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
		$root . '/ci/tests/phpstan/vendor/php-stubs/wp-cli-stubs/wp-cli-stubs.php',
		$root . '/ci/tests/phpstan/vendor/php-stubs/wp-cli-stubs/wp-cli-commands-stubs.php',
		$root . '/ci/tests/phpstan/vendor/php-stubs/wp-cli-stubs/wp-cli-i18n-stubs.php',
		$root . '/ci/tests/phpstan/vendor/php-stubs/wp-cli-stubs/wp-cli-tools-stubs.php',
	];
	$scan_files = $make_neon_array( 4, $scan_files_array );

	// If the SUT ships a Composer vendor/autoload.php, exclude the vendor directory from scanning
	// entirely and rely on PHP reflection via the autoloader instead. This prevents PHPStan from
	// spending excessive time inferring literal types from large data-heavy vendor packages (e.g.
	// barcode or PDF libraries with thousands of lines of constant array definitions).
	$bootstrap_files_section = '';
	$sut_autoload            = "$plugins_dir/$sut_slug/vendor/autoload.php";
	if ( file_exists( $sut_autoload ) ) {
		if ( $regression_mode ) {
			$bootstrap_contents = sprintf(
				"<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', %s );\n}\nrequire_once %s;\n",
				var_export( rtrim( $root . '/ci/tmp/wordpress', '/' ) . '/', true ),
				var_export( $sut_autoload, true )
			);
			if ( file_put_contents( $bootstrap_path, $bootstrap_contents ) === false ) {
				throw new RuntimeException( sprintf( 'Unable to write PHPStan bootstrap: %s', $bootstrap_path ), 102 );
			}
			$bootstrap_file = $bootstrap_path;
		} else {
			$bootstrap_file = $sut_autoload;
		}

		$bootstrap_files_section = <<<EOD
  bootstrapFiles:
    - $bootstrap_file
EOD;
		// Extend the analyseAndScan exclusions to cover the whole vendor tree.
		// Marked optional ( ' (?)' ) for the same version-drift reason as the per-plugin excludes.
		$vendor_dir   = "$plugins_dir/$sut_slug/vendor";
		$vendor_entry = str_repeat( ' ', 8 ) . '- ' . $vendor_dir . ' (?)';
		if ( empty( $exclude_analyse_and_scan_paths ) ) {
			$exclude_analyse_and_scan_paths = $vendor_entry;
		} else {
			$exclude_analyse_and_scan_paths .= PHP_EOL . $vendor_entry;
		}
	}

	// Build final phpstan.neon
	$phpstan = <<<PHPSTAN
parameters:
  level: $level
  phpVersion: $php_version_id
$ignore_errors
  paths:
$paths
  excludePaths:
    analyse:
$exclude_paths
    analyseAndScan:
$exclude_analyse_and_scan_paths
  scanDirectories:
$scan_directories
  scanFiles:
$scan_files
$bootstrap_files_section
  parallel:
    jobSize: 20
    maximumNumberOfProcesses: 8
    minimumNumberOfJobsPerProcess: 2
  inferPrivatePropertyTypeFromConstructor: true
  reportUnmatchedIgnoredErrors: false
$includes_section
PHPSTAN;

	if ( file_put_contents( $config_path, $phpstan ) === false ) {
		throw new RuntimeException( sprintf( 'Unable to write PHPStan config: %s', $config_path ), 103 );
	}
	echo "Successfully generated phpstan.neon including individual stub files.\n";

} catch ( Exception $e ) {
	var_dump( $e->getMessage() );
	die( $e->getCode() );
}
