<?php
/**
 * Plugin Name: WP-CLI Bridge
 * Description: Executes the small set of WP-CLI commands the Woo Core E2E specs issue, over REST, so upstream specs run unmodified.
 *
 * WooCommerce Core's suite reaches WP-CLI through `wp-env run cli`. QIT has no
 * wp-env: WP-CLI is only available in `qit-test.json` `phases.globalSetup`, which
 * finishes before Playwright starts, and the Playwright process itself runs on the
 * host rather than inside the container. Rather than fork every spec that needs a
 * CLI call, this exposes the same operations over REST and `utils/cli.ts` speaks
 * to it, keeping `wpCLI( 'wp ...' )` working with upstream's exact signature.
 *
 * Only the commands the specs actually issue are implemented. Anything else is
 * rejected loudly, so an unported command surfaces as a clear error instead of a
 * silent no-op — and so this stays a bounded surface rather than remote shell
 * execution.
 *
 * @package qit-woo-e2e
 */

declare(strict_types=1);

/**
 * Split a command string into argv, honouring single and double quotes.
 *
 * @param string $command The command string.
 * @return array<int, string>
 */
function qit_e2e_cli_tokenize( string $command ): array {
	$tokens  = array();
	$current = '';
	$quote   = null;
	$started = false;

	$length = strlen( $command );

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $command[ $i ];

		if ( null !== $quote ) {
			if ( $char === $quote ) {
				$quote = null;
			} else {
				$current .= $char;
			}
			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote   = $char;
			$started = true;
			continue;
		}

		if ( ' ' === $char || "\t" === $char || "\n" === $char ) {
			if ( '' !== $current || $started ) {
				$tokens[] = $current;
				$current  = '';
				$started  = false;
			}
			continue;
		}

		$current .= $char;
	}

	if ( '' !== $current || $started ) {
		$tokens[] = $current;
	}

	return $tokens;
}

/**
 * Rewrite a hardcoded `wp_` user-meta prefix to this install's real blog prefix.
 *
 * Per-screen preferences like `wp_metaboxhidden_<screen>` are blog-prefixed user
 * meta. WooCommerce Core's specs hardcode the `wp_` prefix because wp-env always
 * uses it; QIT does not provision the environment, so the prefix is not
 * guaranteed. Resolving it here keeps the assumption out of the spec, which stays
 * identical to upstream.
 *
 * @param string $key The user meta key.
 * @return string The key with the correct blog prefix.
 */
function qit_e2e_cli_resolve_blog_prefix( string $key ): string {
	global $wpdb;

	$prefix = $wpdb->get_blog_prefix();

	if ( 'wp_' === $prefix || 0 !== strpos( $key, 'wp_' ) ) {
		return $key;
	}

	return $prefix . substr( $key, strlen( 'wp_' ) );
}

/**
 * Run one of the supported WP-CLI commands.
 *
 * @param string $command The command string, e.g. `wp option delete foo`.
 * @return array{stdout:string,stderr:string}
 * @throws InvalidArgumentException When the command is not one of the supported ones.
 */
function qit_e2e_cli_execute( string $command ): array {
	$argv = qit_e2e_cli_tokenize( $command );

	// Drop the leading `wp` and any global flags the specs pass through
	// (`--skip-plugins`, `--skip-themes`, `--user=…`); they only matter to a real
	// WP-CLI bootstrap, which is not what is running here.
	if ( isset( $argv[0] ) && 'wp' === $argv[0] ) {
		array_shift( $argv );
	}

	$format = null;
	$positional = array();

	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--format=' ) ) {
			$format = substr( $arg, strlen( '--format=' ) );
			continue;
		}

		if ( 0 === strpos( $arg, '--' ) ) {
			continue;
		}

		$positional[] = $arg;
	}

	$command_name = implode( ' ', array_slice( $positional, 0, 3 ) );
	$decode       = static function ( $value ) use ( $format ) {
		return 'json' === $format ? json_decode( (string) $value, true ) : $value;
	};

	// `wp user meta update <user> <key> <value>`
	if ( 0 === strpos( $command_name, 'user meta update' ) ) {
		list( , , , $user, $key, $value ) = array_pad( $positional, 6, null );
		$key = qit_e2e_cli_resolve_blog_prefix( (string) $key );
		update_user_meta( (int) $user, $key, $decode( $value ) );

		return array(
			'stdout' => sprintf( "Success: Updated custom field '%s'.\n", $key ),
			'stderr' => '',
		);
	}

	// `wp user meta delete <user> <key>`
	if ( 0 === strpos( $command_name, 'user meta delete' ) ) {
		list( , , , $user, $key ) = array_pad( $positional, 5, null );
		$key = qit_e2e_cli_resolve_blog_prefix( (string) $key );
		delete_user_meta( (int) $user, $key );

		return array(
			'stdout' => sprintf( "Success: Deleted custom field '%s'.\n", $key ),
			'stderr' => '',
		);
	}

	// `wp option set <name> <value>`
	if ( 0 === strpos( $command_name, 'option set' ) ) {
		list( , , $name, $value ) = array_pad( $positional, 4, null );
		update_option( (string) $name, $decode( $value ) );

		return array(
			'stdout' => sprintf( "Success: Updated '%s' option.\n", $name ),
			'stderr' => '',
		);
	}

	// `wp option delete <name>`
	if ( 0 === strpos( $command_name, 'option delete' ) ) {
		list( , , $name ) = array_pad( $positional, 3, null );
		delete_option( (string) $name );

		return array(
			'stdout' => sprintf( "Success: Deleted '%s' option.\n", $name ),
			'stderr' => '',
		);
	}

	// `wp plugin activate <plugin>` / `wp plugin deactivate <plugin>`
	if ( 0 === strpos( $command_name, 'plugin activate' ) || 0 === strpos( $command_name, 'plugin deactivate' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		list( , $action, $plugin ) = array_pad( $positional, 3, null );

		if ( 'activate' === $action ) {
			$result = activate_plugin( (string) $plugin );

			if ( is_wp_error( $result ) ) {
				return array(
					'stdout' => '',
					'stderr' => sprintf( "Error: %s\n", $result->get_error_message() ),
				);
			}

			return array(
				'stdout' => sprintf( "Plugin '%s' activated.\nSuccess: Activated 1 of 1 plugins.\n", $plugin ),
				'stderr' => '',
			);
		}

		deactivate_plugins( array( (string) $plugin ) );

		return array(
			'stdout' => sprintf( "Plugin '%s' deactivated.\nSuccess: Deactivated 1 of 1 plugins.\n", $plugin ),
			'stderr' => '',
		);
	}

	throw new InvalidArgumentException(
		sprintf(
			'Unsupported WP-CLI command: "%s". Add it to bootstrap/wp-cli-bridge.php if a ported spec needs it.',
			$command
		)
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'e2e-cli',
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) {
					$command = (string) $request['command'];

					try {
						$result = qit_e2e_cli_execute( $command );
					} catch ( InvalidArgumentException $e ) {
						return new WP_REST_Response( array( 'message' => $e->getMessage() ), 400 );
					}

					return new WP_REST_Response( $result, 200 );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
);
