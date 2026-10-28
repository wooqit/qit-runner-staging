<?php
/**
 * QIT API fuzz route exporter, test authentication, and request instrumentation.
 *
 * This file is installed only in disposable API-fuzz environments. Containment hooks are active
 * only for requests carrying the X-QIT-API-Fuzz marker, so setup and route discovery behave like
 * ordinary WordPress requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function qit_api_fuzz_is_request(): bool {
	return isset( $_SERVER['HTTP_X_QIT_API_FUZZ'] ) && $_SERVER['HTTP_X_QIT_API_FUZZ'] === '1';
}

function qit_api_fuzz_event_file(): string {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . 'qit-api-fuzz-events.jsonl';
}

/**
 * @param array<string,mixed> $event Event fields to append.
 */
function qit_api_fuzz_record_event( array $event ): void {
	$event['recorded_at'] = gmdate( 'c' );
	$event['request_id']  = sanitize_text_field( $_SERVER['HTTP_X_QIT_API_FUZZ_REQUEST_ID'] ?? '' );
	file_put_contents( qit_api_fuzz_event_file(), wp_json_encode( $event ) . "\n", FILE_APPEND | LOCK_EX );
}

// The ordinary shutdown observer below sees fatals from request dispatch, but PHP runs shutdown
// callbacks in registration order. A SUT can register a later callback that throws after our
// observer has already run. Record uncaught Throwables when PHP dispatches them so those late
// shutdown faults are not invisible. Rethrowing preserves normal fatal behavior and status handling.
if ( qit_api_fuzz_is_request() ) {
	set_exception_handler( function ( Throwable $error ): void {
		qit_api_fuzz_record_event( [
			'type'          => 'php_fatal',
			'error_type'    => E_ERROR,
			'error_message' => $error->getMessage(),
			'error_file'    => $error->getFile(),
			'error_line'    => $error->getLine(),
		] );

		throw $error;
	} );
}

// Basic authentication is restricted to the disposable harness and uses normal WP credentials.
add_filter( 'determine_current_user', function ( $user_id ) {
	if ( $user_id || ! qit_api_fuzz_is_request() || empty( $_SERVER['PHP_AUTH_USER'] ) || ! isset( $_SERVER['PHP_AUTH_PW'] ) ) {
		return $user_id;
	}

	$user = wp_authenticate(
		sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ),
		wp_unslash( $_SERVER['PHP_AUTH_PW'] )
	);

	return is_wp_error( $user ) ? 0 : $user->ID;
}, 20 );

// Prevent the SUT from reaching third-party services while a fuzz request is executing.
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( ! qit_api_fuzz_is_request() ) {
		return $preempt;
	}

	$target_host = wp_parse_url( $url, PHP_URL_HOST );
	$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $target_host && $site_host && strtolower( $target_host ) === strtolower( $site_host ) ) {
		return $preempt;
	}

	qit_api_fuzz_record_event( [
		'type' => 'outbound_http_blocked',
		'host' => is_string( $target_host ) ? $target_host : '',
	] );

	return new WP_Error( 'qit_api_fuzz_external_http_blocked', 'External HTTP is disabled during API fuzzing.' );
}, 10, 3 );

add_filter( 'pre_wp_mail', function ( $return ) {
	if ( qit_api_fuzz_is_request() ) {
		qit_api_fuzz_record_event( [ 'type' => 'email_blocked' ] );
		return true;
	}
	return $return;
} );

// This filter runs only after route matching and permission checks, immediately before the
// endpoint callback. It provides a reliable reachability signal even when the callback returns a
// domain-level 404 response.
add_filter( 'rest_dispatch_request', function ( $dispatch_result, $request, $route ) {
	if ( qit_api_fuzz_is_request() && ! str_starts_with( $request->get_route(), '/qit-api-fuzz/v1/' ) ) {
		qit_api_fuzz_record_event( [
			'type'   => 'rest_callback_reached',
			'method' => $request->get_method(),
			'route'  => $route,
		] );
	}
	return $dispatch_result;
}, 1, 3 );

add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( qit_api_fuzz_is_request() && $response instanceof WP_REST_Response && $response->get_status() >= 500 ) {
		qit_api_fuzz_record_event( [
			'type'            => 'rest_5xx',
			'method'          => $request->get_method(),
			'route'           => $request->get_route(),
			'response_status' => $response->get_status(),
		] );
	}
	return $response;
}, 10, 3 );

register_shutdown_function( function () {
	if ( ! qit_api_fuzz_is_request() ) {
		return;
	}

	$error = error_get_last();
	if ( ! is_array( $error ) || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
		return;
	}

	qit_api_fuzz_record_event( [
		'type'          => 'php_fatal',
		'error_type'    => $error['type'],
		'error_message' => $error['message'],
		'error_file'    => $error['file'],
		'error_line'    => $error['line'],
	] );
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'qit-api-fuzz/v1', '/events/(?P<request_id>[A-Za-z0-9-]+)', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$request_id = $request->get_param( 'request_id' );
			$events     = [];
			$file       = qit_api_fuzz_event_file();
			if ( is_readable( $file ) ) {
				$handle = fopen( $file, 'r' );
				if ( $handle ) {
					while ( ( $line = fgets( $handle ) ) !== false ) {
						$event = json_decode( $line, true );
						if ( is_array( $event ) && isset( $event['request_id'] ) && hash_equals( (string) $event['request_id'], (string) $request_id ) ) {
							$events[] = $event;
						}
					}
					fclose( $handle );
				}
			}
			return rest_ensure_response( $events );
		},
		'args'                => [
			'request_id' => [
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z0-9-]+$',
			],
		],
	] );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class QIT_API_Fuzz_Routes_Command {
		/**
		 * Export the registered REST operation metadata.
		 *
		 * ## OPTIONS
		 *
		 * --output=<path>
		 * : Destination JSON path.
		 *
		 * @param array<int,string>    $args       Positional arguments.
		 * @param array<string,string> $assoc_args Named arguments.
		 */
		public function __invoke( array $args, array $assoc_args ): void {
			if ( empty( $assoc_args['output'] ) ) {
				WP_CLI::error( '--output is required.' );
			}

			$server = rest_get_server();
			$routes = [];
			foreach ( $server->get_routes() as $route => $handlers ) {
				$routes[ $route ] = [];
				foreach ( $handlers as $handler ) {
					if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
						continue;
					}
					$callback = $handler['callback'] ?? null;
					$routes[ $route ][] = [
						'methods'             => $handler['methods'],
						'callback'            => self::describe_callback( $callback ),
						'permission_callback' => self::describe_callback( $handler['permission_callback'] ?? null ),
						'registration_source' => self::describe_callback( $callback ),
						'args'                 => self::normalize_value( $handler['args'] ?? [] ),
						'schema'               => self::callback_schema( $callback ),
					];
				}
			}
			ksort( $routes );

			$output = [
				'schema_version' => '1.0.0',
				'generated_at'   => gmdate( 'c' ),
				'site_url'       => home_url(),
				'active_plugins' => array_values( (array) get_option( 'active_plugins', [] ) ),
				'routes'         => $routes,
			];

			$written = file_put_contents(
				$assoc_args['output'],
				wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
			if ( $written === false ) {
				WP_CLI::error( 'Unable to write route export.' );
			}
			WP_CLI::success( sprintf( 'Exported %d routes.', count( $routes ) ) );
		}

		/** @return array<string,mixed> */
		private static function describe_callback( $callback ): array {
			try {
				if ( is_array( $callback ) && count( $callback ) === 2 ) {
					$reflection = new ReflectionMethod( $callback[0], $callback[1] );
					$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
					return [
						'type'            => 'method',
						'class'           => $class,
						'declaring_class' => $reflection->getDeclaringClass()->getName(),
						'method'          => (string) $callback[1],
						'file'            => (string) $reflection->getFileName(),
						'line'            => $reflection->getStartLine(),
					];
				}
				if ( is_string( $callback ) && function_exists( $callback ) ) {
					$reflection = new ReflectionFunction( $callback );
					return [
						'type'     => 'function',
						'function' => $callback,
						'file'     => (string) $reflection->getFileName(),
						'line'     => $reflection->getStartLine(),
					];
				}
				if ( $callback instanceof Closure ) {
					$reflection = new ReflectionFunction( $callback );
					return [
						'type' => 'closure',
						'file' => (string) $reflection->getFileName(),
						'line' => $reflection->getStartLine(),
					];
				}
			} catch ( ReflectionException $e ) {
				return [ 'type' => 'unreflectable' ];
			}
			return [];
		}

		/** @return array<string,mixed> */
		private static function callback_schema( $callback ): array {
			if ( ! is_array( $callback ) || ! isset( $callback[0] ) || ! is_object( $callback[0] ) ) {
				return [];
			}
			foreach ( [ 'get_public_item_schema', 'get_item_schema' ] as $method ) {
				if ( is_callable( [ $callback[0], $method ] ) ) {
					try {
						$schema = $callback[0]->{$method}();
						return is_array( $schema ) ? self::normalize_value( $schema ) : [];
					} catch ( Throwable $e ) {
						return [];
					}
				}
			}
			return [];
		}

		private static function normalize_value( $value ) {
			if ( is_array( $value ) ) {
				$normalized = [];
				foreach ( $value as $key => $item ) {
					if ( in_array( $key, [ 'validate_callback', 'sanitize_callback' ], true ) ) {
						$normalized[ $key ] = self::describe_callback( $item );
					} else {
						$normalized[ $key ] = self::normalize_value( $item );
					}
				}
				return $normalized;
			}
			if ( is_scalar( $value ) || $value === null ) {
				return $value;
			}
			return self::describe_callback( $value );
		}
	}

	WP_CLI::add_command( 'qit-api-fuzz routes', QIT_API_Fuzz_Routes_Command::class );
}
