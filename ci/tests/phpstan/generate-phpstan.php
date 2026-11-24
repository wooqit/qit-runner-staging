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
		"$plugins_dir/$sut_slug/vendor_prefixed/*",
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

	// woocommerce/php-stubs: stubs of Woo extensions, so calls from the SUT into an extension
	// that isn't in the run's plugin stack are analysed instead of reported as class.notFound.
	// Opt-in per run, and never in compatibility-regression mode, where WooCommerce must be the
	// only thing that changes between the baseline and target passes. When this env is unset,
	// the generated config is byte-identical to before.
	$woo_stubs_enabled = ! $regression_mode
		&& isset( $env['PHPSTAN_WOO_STUBS'] )
		&& filter_var( $env['PHPSTAN_WOO_STUBS'], FILTER_VALIDATE_BOOLEAN );

	// WooCommerce itself rarely calls into extensions, so the stubs find little there, and on
	// production they made its PHPStan step run past the 15-minute limit (about 3 minutes
	// without them). Analyse it as if the stubs were off.
	if ( $woo_stubs_enabled && $sut_type === 'plugin' && $sut_slug === 'woocommerce' ) {
		$woo_stubs_enabled = false;
		echo "woocommerce/php-stubs: off, the SUT is WooCommerce.\n";
	}

	if ( $woo_stubs_enabled ) {
		$woo_stubs_dir = $root . '/ci/tests/phpstan/vendor/woocommerce/php-stubs/stubs';
		if ( ! is_dir( $woo_stubs_dir ) ) {
			throw new RuntimeException( sprintf( 'PHPSTAN_WOO_STUBS is on, but woocommerce/php-stubs is not installed: %s', $woo_stubs_dir ), 104 );
		}

		// php-stubs never stands in for anything installed in the run's stack (the SUT
		// included). The SUT is analysed from its real code, and the stack's plugins (or
		// themes, for a theme SUT) from stubz stubs of the downloaded version. Nothing scans
		// the other kind (a theme SUT's plugins, a plugin SUT's themes), but those are left out
		// too: a php-stubs release that differs from the installed one would check calls
		// against the wrong signatures. This only sees what's installed as a directory in
		// ci/plugins or ci/themes. Stubs that declare the SUT's own classes are left out further
		// down, but a copy of an extension or framework bundled inside the SUT (e.g. under
		// vendor/) isn't detected, so a php-stubs release can still shadow it.
		$skip_slugs = [];
		foreach ( [ 'plugin', 'theme' ] as $stack_type ) {
			$stack_dir = $root . '/ci/' . $stack_type . 's';
			if ( ! is_dir( $stack_dir ) ) {
				continue;
			}
			foreach ( new DirectoryIterator( $stack_dir ) as $item ) {
				if ( $item->isDir() && ! $item->isDot() ) {
					$skip_slugs[ $item->getBasename() ] = $skip_slugs[ $item->getBasename() ] ?? "in the $stack_type stack";
				}
			}
		}

		// A SUT whose own PHPStan config already includes php-stubs for an extension keeps its
		// choice, which is usually a pin to the minimum version it supports. The pin often
		// lives in a separate .neon the SUT config includes, so follow its includes, staying
		// inside the SUT and out of its vendor dir.
		$sut_dir      = realpath( "$plugins_dir/$sut_slug" );
		$sut_configs  = array_values( array_filter( $files_to_include, function ( $path ) use ( $plugins_dir, $sut_slug ) {
			return strpos( $path, "$plugins_dir/$sut_slug/" ) === 0;
		} ) );
		$seen_configs = [];
		while ( $sut_dir !== false && ! empty( $sut_configs ) ) {
			$sut_config = realpath( array_shift( $sut_configs ) );
			if (
				$sut_config === false ||
				isset( $seen_configs[ $sut_config ] ) ||
				strpos( $sut_config, "$sut_dir/" ) !== 0 ||
				strpos( $sut_config, "$sut_dir/vendor/" ) === 0
			) {
				continue;
			}
			$seen_configs[ $sut_config ] = true;
			$contents                    = (string) file_get_contents( $sut_config );

			// The slug ends at the first character a slug can't contain, so a path ending in
			// "<slug>", "<slug>/", "<slug>'" or "<slug>/7.0.0.neon" all match.
			if ( preg_match_all( '~woocommerce/php-stubs/stubs/([a-z0-9-]+)~', $contents, $matches ) ) {
				foreach ( $matches[1] as $slug ) {
					$skip_slugs[ $slug ] = $skip_slugs[ $slug ] ?? 'included by the SUT config';
				}
			}

			// List items that are .neon paths are includes. Paths with %parameters% are skipped.
			if ( preg_match_all( '~^\s*-\s*[\'"]?([^\'"\s#%]+\.neon)~m', $contents, $include_matches ) ) {
				foreach ( $include_matches[1] as $include ) {
					$sut_configs[] = $include[0] === '/' ? $include : dirname( $sut_config ) . '/' . $include;
				}
			}
		}

		// Lowercased fully qualified names of the classes, interfaces, traits and enums declared
		// in $code. A line-based match, not a parser: good enough for generated stubs and for
		// declarations at the start of a line in plugin code.
		$declared_classes = function ( $code ) {
			$found = [];
			preg_match_all(
				'~^[ \t]*(?:namespace[ \t]+([A-Za-z_\\\\][\w\\\\]*)?[ \t]*[;{]|(?:(?:final|abstract|readonly)[ \t]+)*(?:class|interface|trait|enum)[ \t]+([A-Za-z_]\w*))~m',
				$code,
				$matches,
				PREG_SET_ORDER
			);
			$namespace = '';
			foreach ( $matches as $match ) {
				if ( isset( $match[2] ) && $match[2] !== '' ) {
					$found[ strtolower( ltrim( $namespace . '\\' . $match[2], '\\' ) ) ] = true;
				} else {
					$namespace = $match[1] ?? '';
				}
			}

			return $found;
		};

		// The PHP files an extension's stubs .neon scans, following its includes.
		$stub_files = function ( $neon, array &$seen = [] ) use ( &$stub_files ) {
			$real = realpath( $neon );
			if ( $real === false || isset( $seen[ $real ] ) ) {
				return [];
			}
			$seen[ $real ] = true;
			$files         = [];
			preg_match_all( '~^\s*-\s*[\'"]?([^\'"\s#]+\.(neon|php))~m', (string) file_get_contents( $real ), $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$path = dirname( $real ) . '/' . $match[1];
				if ( $match[2] === 'neon' ) {
					$files = array_merge( $files, $stub_files( $path, $seen ) );
				} elseif ( is_file( $path ) ) {
					$files[] = (string) realpath( $path );
				}
			}

			return $files;
		};

		// A stub that declares one of the SUT's own classes would shadow the SUT's real code,
		// and PHPStan would report the SUT's own calls as wrong. For example, Subscriptions 9.x
		// includes Gifting, so its stubs declare the standalone Gifting plugin's WCS_Gifting.
		// Those extensions are left out. Bundled third-party code isn't checked (see above).
		$sut_classes = [];
		if ( $sut_dir !== false ) {
			$bundled_dirs = [ 'vendor', 'vendor-prefixed', 'vendor_prefixed', 'vendor-scoped', 'generated', 'lib-3rd-party', 'node_modules' ];
			$sut_files    = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $sut_dir, FilesystemIterator::SKIP_DOTS ),
					function ( SplFileInfo $file ) use ( $bundled_dirs ) {
						return ! $file->isDir() || ! in_array( $file->getBasename(), $bundled_dirs, true );
					}
				)
			);
			foreach ( $sut_files as $file ) {
				if ( $file->isFile() && strtolower( $file->getExtension() ) === 'php' ) {
					$sut_classes += $declared_classes( (string) file_get_contents( $file->getPathname() ) );
				}
			}
		}

		$woo_stubs_included = [];
		foreach ( new DirectoryIterator( $woo_stubs_dir ) as $item ) {
			$slug = $item->getBasename();
			if ( ! $item->isDir() || $item->isDot() || isset( $skip_slugs[ $slug ] ) ) {
				continue;
			}
			// Shared framework stubs have no .neon of their own; the extensions that need
			// them list them in their scanFiles.
			if ( ! file_exists( $item->getPathname() . '/latest.neon' ) ) {
				continue;
			}
			if ( ! empty( $sut_classes ) ) {
				$shared = [];
				foreach ( $stub_files( $item->getPathname() . '/latest.neon' ) as $stub_file ) {
					$shared += array_intersect_key( $declared_classes( (string) file_get_contents( $stub_file ) ), $sut_classes );
				}
				if ( ! empty( $shared ) ) {
					ksort( $shared );
					$skip_slugs[ $slug ] = sprintf(
						"declares %d of the SUT's classes, e.g. %s",
						count( $shared ),
						implode( ', ', array_slice( array_keys( $shared ), 0, 3 ) )
					);
					continue;
				}
			}
			$woo_stubs_included[] = $slug;
		}
		sort( $woo_stubs_included );

		foreach ( $woo_stubs_included as $slug ) {
			$files_to_include[] = "$woo_stubs_dir/$slug/latest.neon";
		}

		ksort( $skip_slugs );
		echo sprintf( "woocommerce/php-stubs: including %d extension(s).\n", count( $woo_stubs_included ) );
		foreach ( $skip_slugs as $slug => $reason ) {
			if ( is_dir( "$woo_stubs_dir/$slug" ) ) {
				echo "woocommerce/php-stubs: skipping $slug ($reason).\n";
			}
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
