<?php
/*
 * Plugin name: QIT - WP CLI GitHub Cache
 * Issue: https://github.com/wp-cli/extension-command/issues/363
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/*
 * Cache downloads of "wp plugin install" from GitHub repos.
 */
WP_CLI::add_hook( 'before_run_command', static function ( $args, $assoc_args, $options ) {
	// Early bail: Unexpected value;
	if ( ! is_array( $args ) || count( $args ) < 3 ) {
		return;
	}

	// We want only "plugin install"
	if ( $args[0] !== 'plugin' || $args[1] !== 'install' ) {
		return;
	}

	// We want only plugin installs of GitHub repos.
	if ( stripos( $args[2], 'github.com' ) === false ) {
		return;
	}

	// Invalid URL.
	if ( ! filter_var( $args[2], FILTER_VALIDATE_URL ) ) {
		return;
	}

	// Validate the URL format. Expected: github.com/AUTHOR/REPO/ANYTHING_ELSE
	if ( ! preg_match( '#^https?://(?:gist\.)?github\.com/[^/]+/[^/]+#', $args[2] ) ) {
		WP_CLI::warning( 'Invalid GitHub URL format. Expected format: github.com/AUTHOR/REPO' );

		return;
	}

	// URL: https://github.com/author/repo/archive/refs/heads/trunk.zip
	// Becomes:
	// ['github.com', 'author', 'repo', 'archive', 'refs', 'heads', 'trunk.zip']
	$parts = explode( '/', preg_replace( '#https?://#', '', $args[2] ) );

	// Minimum: github.com, author, repo, file
	if ( count( $parts ) < 4 ) {
		WP_CLI::warning( 'Invalid GitHub URL format.' );

		return;
	}

	$author = $parts[1];
	$repo   = $parts[2];

	// trunk.zip
	$zip = end( $parts );

	if ( ! str_ends_with( $zip, '.zip' ) ) {
		return;
	}

	// Create the custom cache file name
	$cache_file_name = sprintf( '%s-%s-%s', $author, $repo, basename( $zip, '.zip' ) );

	$cache_file_name = str_replace( '..', '', $cache_file_name );
	$cache_file_name = sanitize_file_name( $cache_file_name );

	WP_CLI::get_http_cache_manager()->whitelist_package( $args[2], 'plugin', $cache_file_name, 'github', 86400 );
} );
