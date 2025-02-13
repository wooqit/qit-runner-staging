<?php

$env = getenv();

$required_envs = [
	'SUT_TYPE',
	'GITHUB_WORKSPACE',
];

foreach ( $required_envs as $required_env ) {
	if ( ! isset( $env[ $required_env ] ) ) {
		echo "Missing required env: $required_env\n";
		die( 1 );
	}
}

$root = $env['GITHUB_WORKSPACE'];

try {
	if ( ! isset( $argv[1] ) ) {
		throw new RuntimeException( 'Run this as php generate-phpstan.php SUT_SLUG', 100 );
	}

	$sut_slug     = $argv[1];
	$plugins_dir  = $root . '/ci/' . $env['SUT_TYPE'] . 's';
	$dependencies = [];
	$level        = 0;

	$it = new DirectoryIterator( $plugins_dir );
	while ( $it->valid() ) {
		if ( $it->current()->isDir() && ! $it->current()->isDot() && $it->current()->getBasename() !== $sut_slug && substr( $it->current()->getBasename(), 0, 1 ) !== '_' ) {
			$dependencies[] = $it->current()->getPathname();
		}
		$it->next();
	}

	$make_neon_array = function ( $indentation, $array ) {
		return str_repeat( ' ', $indentation ) . '- ' . implode( PHP_EOL . str_repeat( ' ', $indentation ) . '- ', $array );
	};

	$paths = $make_neon_array( 4, [
		"$plugins_dir/$sut_slug",
	] );

	$scan_directories = $make_neon_array( 4, array_merge( [
		$root . '/ci/tmp/wordpress',
	], $dependencies ) );

	// Folders that are scanned for discovering symbols, but are not analysed.
	$exclude_paths = $make_neon_array(8, [
		"$plugins_dir/$sut_slug/vendor/*",
		"$plugins_dir/$sut_slug/vendor-prefixed/*",
	]);

	// Build includes section
	$files_to_include = [];
	$includes_section = '';

	if ( file_exists( "$plugins_dir/$sut_slug/phpstan.neon" ) ) {
		$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.neon";
	}
	if ( file_exists( "$plugins_dir/$sut_slug/phpstan.neon.dist" ) ) {
		$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.neon.dist";
	}
	if ( file_exists( "$plugins_dir/$sut_slug/phpstan.dist.neon" ) ) {
		$files_to_include[] = "$plugins_dir/$sut_slug/phpstan.dist.neon";
	}

	if ( ! empty( $files_to_include ) ) {
		$files_to_include = $make_neon_array( 4, $files_to_include );
		$includes_section = <<<INCLUDES
includes:
$files_to_include
INCLUDES;
	}

	$phpstan = <<<PHPSTAN
parameters:
  level: $level
  paths:
$paths
  excludePaths:
    analyse:
$exclude_paths
  scanDirectories:
$scan_directories
  parallel:
    jobSize: 10
    maximumNumberOfProcesses: 32
    minimumNumberOfJobsPerProcess: 2
  inferPrivatePropertyTypeFromConstructor: true
  reportUnmatchedIgnoredErrors: false
$includes_section
PHPSTAN;

	file_put_contents( __DIR__ . '/phpstan.neon', $phpstan );

} catch ( Exception $e ) {
	var_dump( $e->getMessage() );
	die( $e->getCode() );
}
