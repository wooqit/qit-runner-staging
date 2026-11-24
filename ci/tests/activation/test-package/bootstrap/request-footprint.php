<?php
/*
 * Plugin Name: Activation Request Footprint
 * Description: Records the queries, database writes and outbound HTTP calls a plugin makes on marked QIT probe requests.
 */

const QIT_REQUEST_FOOTPRINT_MAX_CALL_SITES = 20;
const QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES = 20;
const QIT_REQUEST_FOOTPRINT_SAMPLE_LENGTH  = 160;
const QIT_REQUEST_FOOTPRINT_NO_HOOK        = '(no hook)';

/**
 * The plugin to measure, as its directory (or single file) under the plugins directory.
 * Empty unless this is a marked, token-authenticated footprint probe.
 */
function qit_request_footprint_plugin(): string {
	if ( ! function_exists( 'qit_activation_smoke_is_request' ) || ! qit_activation_smoke_is_request() ) {
		return '';
	}

	$plugin = (string) wp_unslash( $_SERVER['HTTP_X_QIT_REQUEST_FOOTPRINT'] ?? '' );

	return preg_match( '/^[A-Za-z0-9._-]+$/', $plugin ) && '.' !== $plugin[0] ? $plugin : '';
}

/**
 * @return array{file:string,line:int}|null The innermost frame in the plugin, if it is on the call stack.
 */
function qit_request_footprint_plugin_frame( string $plugin_path ): ?array {
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		$file = $frame['file'] ?? '';
		if ( '' !== $file && 0 === strpos( $file, $plugin_path ) ) {
			return [
				'file' => substr( $file, strlen( WP_PLUGIN_DIR ) + 1 ),
				'line' => (int) ( $frame['line'] ?? 0 ),
			];
		}
	}

	return null;
}

// Keeps the statement's shape but not its values, which can be credentials or personal data.
function qit_request_footprint_redact( string $sql ): string {
	$sql = substr( trim( $sql ), 0, QIT_REQUEST_FOOTPRINT_SAMPLE_LENGTH * 2 );
	$sql = (string) preg_replace( [ "/'(?:[^'\\\\]|\\\\.)*'?/s", '/"(?:[^"\\\\]|\\\\.)*"?/s', '/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?)\b/i', '/\s+/' ], [ '?', '?', '?', ' ' ], $sql );

	return substr( $sql, 0, QIT_REQUEST_FOOTPRINT_SAMPLE_LENGTH );
}

/**
 * Unlike call sites, hooks have no cap: work past the call-site cap still names its hook.
 *
 * @param array<string,array<string,mixed>> $hook_totals
 */
function qit_request_footprint_add_to_hook_total( array &$hook_totals, string $hook, string $file, string $kind ): void {
	if ( ! isset( $hook_totals[ $hook ] ) ) {
		$hook_totals[ $hook ] = [
			'hook'          => $hook,
			'queries'       => 0,
			'writes'        => 0,
			'http'          => 0,
			'files'         => [],
			'omitted_files' => [],
		];
	}
	$total = &$hook_totals[ $hook ];

	if ( ! isset( $total['files'][ $file ] ) ) {
		if ( count( $total['files'] ) >= QIT_REQUEST_FOOTPRINT_MAX_HOOK_FILES ) {
			$total['omitted_files'][ $file ] = true;
		} else {
			$total['files'][ $file ] = [
				'file'    => $file,
				'queries' => 0,
				'writes'  => 0,
				'http'    => 0,
			];
		}
	}

	foreach ( 'writes' === $kind ? [ 'queries', 'writes' ] : [ $kind ] as $metric ) {
		++$total[ $metric ];
		if ( isset( $total['files'][ $file ] ) ) {
			++$total['files'][ $file ][ $metric ];
		}
	}
}

/**
 * @param array<string,mixed> $footprint
 */
function qit_request_footprint_record( array &$footprint, string $plugin_path, string $kind, string $sample ): void {
	$frame = qit_request_footprint_plugin_frame( $plugin_path );
	if ( null === $frame ) {
		return;
	}

	// Drop the `query` or `pre_http_request` filter this runs inside; the outermost remaining
	// hook is the one that decides whether the work runs on every request.
	$hooks = $GLOBALS['wp_current_filter'] ?? [];
	array_pop( $hooks );
	// do_action() accepts any scalar as a hook name.
	$hook = (string) ( $hooks[0] ?? QIT_REQUEST_FOOTPRINT_NO_HOOK );

	// `queries` counts every statement, so a write is counted in both.
	++$footprint['sut'][ $kind ];
	if ( 'writes' === $kind ) {
		++$footprint['sut']['queries'];
	}

	qit_request_footprint_add_to_hook_total( $footprint['sut']['hook_totals'], $hook, $frame['file'], $kind );

	$key = $hook . '|' . $frame['file'] . ':' . $frame['line'];
	if ( ! isset( $footprint['sut']['call_sites'][ $key ] ) ) {
		// Read-only sites fill the first slots; writes and HTTP calls, the findings that matter
		// most, keep room of their own so an early burst of reads can't crowd them out.
		$limit = 'queries' === $kind ? QIT_REQUEST_FOOTPRINT_MAX_CALL_SITES : QIT_REQUEST_FOOTPRINT_MAX_CALL_SITES * 2;
		if ( count( $footprint['sut']['call_sites'] ) >= $limit ) {
			$footprint['dropped_call_sites'][ $key ] = true;
			return;
		}

		$footprint['sut']['call_sites'][ $key ] = [
			'hook'    => $hook,
			'file'    => $frame['file'],
			'line'    => $frame['line'],
			'queries' => 0,
			'writes'  => 0,
			'http'    => 0,
			'sample'  => $sample,
		];
	}

	$site = &$footprint['sut']['call_sites'][ $key ];
	if ( 'writes' === $kind ) {
		// A write says more about the call site than the read before it, as in update_option().
		if ( 0 === $site['writes'] ) {
			$site['sample'] = $sample;
		}
		++$site['queries'];
	}
	++$site[ $kind ];
}

add_action( 'muplugins_loaded', function (): void {
	$plugin = qit_request_footprint_plugin();
	if ( '' === $plugin ) {
		return;
	}

	$plugin_path = WP_PLUGIN_DIR . '/' . $plugin . ( '.php' === substr( $plugin, -4 ) ? '' : '/' );
	$footprint   = [
		'plugin'             => $plugin,
		'queries'            => 0,
		'sut'                => [
			'queries'     => 0,
			'writes'      => 0,
			'http'        => 0,
			'call_sites'  => [],
			'hook_totals' => [],
		],
		'dropped_call_sites' => [],
	];

	add_filter( 'query', function ( $query ) use ( &$footprint, $plugin_path ) {
		++$footprint['queries'];

		$sql  = ltrim( (string) $query );
		$kind = preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $sql ) ? 'writes' : 'queries';
		qit_request_footprint_record( $footprint, $plugin_path, $kind, qit_request_footprint_redact( $sql ) );

		return $query;
	}, PHP_INT_MAX );

	// Counted first, so every attempt is seen, and answered locally last, so no later callback
	// can reopen it: probes must not send traffic to vendors, and a slow endpoint would stall
	// the measurement.
	add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$footprint, $plugin_path ) {
		$host = substr( (string) wp_parse_url( (string) $url, PHP_URL_HOST ), 0, QIT_REQUEST_FOOTPRINT_SAMPLE_LENGTH );
		qit_request_footprint_record( $footprint, $plugin_path, 'http', $host );

		return $preempt;
	}, PHP_INT_MIN, 3 );
	add_filter( 'pre_http_request', function ( $preempt ) {
		return false === $preempt
			? new WP_Error( 'qit_request_footprint_http_blocked', 'QIT blocks outbound HTTP on request footprint probes.' )
			: $preempt;
	}, PHP_INT_MAX );

	// WordPress registers its own shutdown handler before mu-plugins load, so this one
	// runs after the `shutdown` action and also sees work plugins do there.
	register_shutdown_function( function () use ( &$footprint, $plugin ): void {
		$footprint['plugin_active']      = in_array( $plugin, array_map(
			function ( $entrypoint ) {
				return explode( '/', (string) $entrypoint, 2 )[0];
			},
			(array) get_option( 'active_plugins', [] )
		), true );
		$footprint['sut']['call_sites']  = array_values( $footprint['sut']['call_sites'] );
		$footprint['sut']['hook_totals'] = array_values( array_map(
			function ( array $hook ): array {
				$hook['files']         = array_values( $hook['files'] );
				$hook['omitted_files'] = count( $hook['omitted_files'] );

				return $hook;
			},
			$footprint['sut']['hook_totals']
		) );
		$footprint['dropped_call_sites'] = count( $footprint['dropped_call_sites'] );

		qit_activation_smoke_record_event( [
			'type'      => 'request_footprint',
			'footprint' => $footprint,
		] );
	} );
} );
