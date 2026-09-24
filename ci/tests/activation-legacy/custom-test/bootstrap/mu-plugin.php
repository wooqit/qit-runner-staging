<?php
/*
 * Plugin Name: Activation Test Mu Plugin
 */

function qit_activation_smoke_is_request(): bool {
	if ( ! isset( $_SERVER['HTTP_X_QIT_ACTIVATION_SMOKE'], $_SERVER['HTTP_X_QIT_ACTIVATION_SMOKE_TOKEN'] ) ) {
		return false;
	}

	$provided_token = (string) wp_unslash( $_SERVER['HTTP_X_QIT_ACTIVATION_SMOKE_TOKEN'] );

	return '1' === $_SERVER['HTTP_X_QIT_ACTIVATION_SMOKE']
		&& '' !== qit_activation_smoke_request_id()
		&& hash_equals( qit_activation_smoke_token(), $provided_token );
}

function qit_activation_smoke_request_id(): string {
	$request_id = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_QIT_ACTIVATION_SMOKE_REQUEST_ID'] ?? '' ) );

	return preg_match( '/^[A-Za-z0-9-]+$/', $request_id ) ? $request_id : '';
}

function qit_activation_smoke_contract(): string {
	return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_QIT_HOOK_CONTRACT'] ?? '' ) );
}

function qit_activation_smoke_token(): string {
	$secret = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
		. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
		. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );

	return hash_hmac( 'sha256', 'qit-activation-smoke', $secret );
}

function qit_activation_smoke_event_directory(): string {
	return trailingslashit( sys_get_temp_dir() ) . 'qit-activation-smoke-' . hash( 'sha256', ABSPATH );
}

function qit_activation_smoke_event_file( string $request_id ): string {
	return trailingslashit( qit_activation_smoke_event_directory() ) . $request_id . '.jsonl';
}

function qit_activation_smoke_cleanup_events(): void {
	$files = glob( trailingslashit( qit_activation_smoke_event_directory() ) . '*.jsonl' );
	if ( false === $files ) {
		return;
	}

	foreach ( $files as $file ) {
		$modified_at = is_file( $file ) ? filemtime( $file ) : false;
		if ( false !== $modified_at && $modified_at < time() - HOUR_IN_SECONDS ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * @param array<string,mixed> $event Event fields to append.
 */
function qit_activation_smoke_record_event( array $event ): void {
	$request_id = qit_activation_smoke_request_id();
	if ( '' === $request_id ) {
		return;
	}

	$event = array_merge(
		[
			'type'            => '',
			'error_type'      => '',
			'error_message'   => '',
			'error_file'      => '',
			'error_line'      => 0,
			'error_trace'     => '',
			'response_status' => null,
			'route'           => sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
		],
		$event
	);

	$event['recorded_at'] = gmdate( 'c' );
	$event['request_id']  = $request_id;
	$event['request_uri'] = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

	$event_directory = qit_activation_smoke_event_directory();
	if ( ! is_dir( $event_directory ) && ! wp_mkdir_p( $event_directory ) ) {
		return;
	}

	file_put_contents(
		qit_activation_smoke_event_file( $request_id ),
		wp_json_encode( $event ) . "\n",
		FILE_APPEND | LOCK_EX
	);
}

if ( qit_activation_smoke_is_request() ) {
	$qit_activation_smoke_recorded_uncaught = null;

	set_exception_handler( function ( Throwable $error ) use ( &$qit_activation_smoke_recorded_uncaught ): void {
		qit_activation_smoke_record_event( [
			'type'          => 'php_fatal',
			'error_type'    => get_class( $error ),
			'error_message' => $error->getMessage(),
			'error_file'    => $error->getFile(),
			'error_line'    => $error->getLine(),
			'error_trace'   => $error->getTraceAsString(),
		] );

		$qit_activation_smoke_recorded_uncaught = [
			'file' => $error->getFile(),
			'line' => $error->getLine(),
		];

		$status = http_response_code();
		if ( false === $status || $status < 500 ) {
			http_response_code( 500 );
		}

		// An exception rethrown from an exception handler goes straight to
		// PHP's native uncaught-fatal path, so the standard debug log still
		// reports the original fatal error.
		throw $error;
	} );

	register_shutdown_function( function () use ( &$qit_activation_smoke_recorded_uncaught ): void {
		$error = error_get_last();
		if ( ! is_array( $error ) || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
			return;
		}

		// Skip only the specific "Uncaught ..." fatal that the exception
		// handler above already recorded before rethrowing. Other uncaught
		// fatals must still be recorded here: a SUT can replace the global
		// exception handler after this mu-plugin registers it, in which case
		// this shutdown handler holds the only evidence.
		if (
			is_array( $qit_activation_smoke_recorded_uncaught )
			&& 0 === strpos( $error['message'], 'Uncaught ' )
			&& $error['file'] === $qit_activation_smoke_recorded_uncaught['file']
			&& $error['line'] === $qit_activation_smoke_recorded_uncaught['line']
		) {
			return;
		}

		qit_activation_smoke_record_event( [
			'type'          => 'php_fatal',
			'error_type'    => $error['type'],
			'error_message' => $error['message'],
			'error_file'    => $error['file'],
			'error_line'    => $error['line'],
			'error_trace'   => '',
		] );
	} );
}

add_filter( 'rest_pre_serve_request', function ( $served ) {
	if ( qit_activation_smoke_is_request() && 'rest_pre_serve_request:null' === qit_activation_smoke_contract() ) {
		return null;
	}

	return $served;
}, PHP_INT_MIN, 4 );

add_filter( 'rest_authentication_errors', function ( $errors ) {
	if ( qit_activation_smoke_is_request() && 'rest_authentication_errors:wp_error' === qit_activation_smoke_contract() ) {
		return new WP_Error(
			'qit_activation_smoke_authentication_error',
			'QIT injected the documented rest_authentication_errors WP_Error contract.',
			[ 'status' => 401 ]
		);
	}

	return $errors;
}, PHP_INT_MIN );

add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( qit_activation_smoke_is_request() && $response instanceof WP_REST_Response && $response->get_status() >= 500 ) {
		qit_activation_smoke_record_event( [
			'type'            => 'rest_5xx',
			'method'          => $request->get_method(),
			'route'           => $request->get_route(),
			'error_message'   => sprintf( 'REST response returned HTTP %d.', $response->get_status() ),
			'response_status' => $response->get_status(),
		] );
	}

	return $response;
}, 10, 3 );

add_action( 'rest_api_init', function (): void {
	register_rest_route( 'qit-activation-smoke/v1', '/session', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function (): WP_REST_Response {
			qit_activation_smoke_cleanup_events();

			return rest_ensure_response( [
				'token' => qit_activation_smoke_token(),
			] );
		},
	] );

	register_rest_route( 'qit-activation-smoke/v1', '/events/(?P<request_id>[A-Za-z0-9-]+)', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ): WP_REST_Response {
			$request_id = (string) $request->get_param( 'request_id' );
			$events     = [];
			$file       = qit_activation_smoke_event_file( $request_id );

			if ( is_readable( $file ) ) {
				$handle = fopen( $file, 'r' );
				if ( false !== $handle ) {
					while ( ( $line = fgets( $handle ) ) !== false ) {
						$event = json_decode( $line, true );
						if ( is_array( $event ) && isset( $event['request_id'] ) && hash_equals( (string) $event['request_id'], $request_id ) ) {
							$events[] = $event;
						}
					}
					fclose( $handle );
				}
				wp_delete_file( $file );
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

	register_rest_route( 'qit-activation-smoke/v1', '/probes/wp-mail', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function (): bool {
			return qit_activation_smoke_is_request() && current_user_can( 'manage_options' );
		},
		'callback'            => function (): WP_REST_Response {
			$transport_intercepted  = false;
			$mail_preempted         = false;
			$install_null_transport = function ( $phpmailer ) use ( &$transport_intercepted ): void {
				$phpmailer->isSendmail();
				$phpmailer->Sendmail = '/bin/true';

				$transport_intercepted = true;
			};
			$observe_preemption     = function ( $return ) use ( &$mail_preempted ) {
				$mail_preempted = null !== $return;

				return $return;
			};
			$set_safe_sender        = function (): string {
				return 'qit-activation-smoke@example.com';
			};

			add_filter( 'wp_mail_from', $set_safe_sender, PHP_INT_MAX );
			add_action( 'phpmailer_init', $install_null_transport, PHP_INT_MIN );
			add_action( 'phpmailer_init', $install_null_transport, PHP_INT_MAX );
			add_filter( 'pre_wp_mail', $observe_preemption, PHP_INT_MAX );

			try {
				$mail_result = wp_mail(
					'qit-activation-smoke@example.invalid',
					'QIT activation mail-pipeline probe',
					'<p>This message is intercepted by the QIT activation probe.</p>',
					[ 'Content-Type: text/html; charset=UTF-8' ]
				);
			} finally {
				remove_filter( 'wp_mail_from', $set_safe_sender, PHP_INT_MAX );
				remove_action( 'phpmailer_init', $install_null_transport, PHP_INT_MIN );
				remove_action( 'phpmailer_init', $install_null_transport, PHP_INT_MAX );
				remove_filter( 'pre_wp_mail', $observe_preemption, PHP_INT_MAX );
			}

			$details = [
				'completed'                   => true,
				'mail_result'                 => (bool) $mail_result,
				'transport_intercepted'       => $transport_intercepted,
				'mail_preempted'              => $mail_preempted,
				// A legal pre_wp_mail short-circuit may report the mail as
				// blocked, so a preempted wp_mail() is accepted regardless of
				// its return value.
				'mail_accepted'               => (bool) $mail_result || $mail_preempted,
				'delivery_safely_intercepted' => $transport_intercepted || $mail_preempted,
			];

			return rest_ensure_response( $details );
		},
	] );

	register_rest_route( 'qit-activation-smoke/v1', '/probes/pre-http-request', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function (): bool {
			return qit_activation_smoke_is_request()
				&& 'pre_http_request:wp_error' === qit_activation_smoke_contract()
				&& current_user_can( 'manage_options' );
		},
		'callback'            => function (): WP_REST_Response {
			$probe_url        = 'http://127.0.0.1:1/qit-activation-smoke';
			$inject_wp_error = function ( $response, $request, $url ) use ( $probe_url ) {
				if ( $probe_url === $url ) {
					return new WP_Error(
						'qit_activation_smoke_http_error',
						'QIT injected the documented pre_http_request WP_Error contract.'
					);
				}

				return $response;
			};

			add_filter( 'pre_http_request', $inject_wp_error, PHP_INT_MIN, 3 );

			try {
				$response = wp_remote_get(
					$probe_url,
					[
						'redirection' => 0,
						'timeout'     => 1,
					]
				);
			} finally {
				remove_filter( 'pre_http_request', $inject_wp_error, PHP_INT_MIN );
			}

			$details = [
				'completed'          => true,
				'result_is_wp_error' => is_wp_error( $response ),
				'result_error_code'  => is_wp_error( $response ) ? $response->get_error_code() : '',
			];

			return rest_ensure_response( $details );
		},
	] );
} );

if ( ! class_exists ( 'QM_Error_Summary' ) ) {
	class QM_Error_Summary {
		private static $instance = null;
        protected string $logs_dir = __DIR__ . '/../plugins/logs/';
		public function __construct() {
			$request_uri    = $_SERVER['REQUEST_URI'];
			$is_api_request = strpos( $request_uri, 'wp-json' ) !== false;

            // Create the logs directory if it does not exist.
            if ( ! is_dir( $this->logs_dir ) ) {
                if ( ! mkdir( $this->logs_dir, 0755, true ) ) {
                    trigger_error( 'Failed to create the logs directory.', E_USER_ERROR );
                }
            }

			// Ignore deprecated errors coming from WordPress Core.
			add_filter( 'qm/collect/php_error_levels', function ( $levels ) {
				$levels['core']['core'] = ( E_ALL & ~E_DEPRECATED );

				return $levels;
			} );

            add_action( 'shutdown', array( $this, 'capture_logs' ) );
		}
		public static function  init() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
		}

		public function capture_logs() {
			// If we disable QM, since this is hooked at shutdown it will be called
			// and will trigger an error on that single request we disabled QM,
			// so bail if the collector is null.
			if ( is_null( \QM_Collectors::get( 'php_errors' ) ) ) {
				return;
			}

			$this->log_collector_entries( \QM_Collectors::get( 'php_errors' ) );
			$this->log_collector_entries( \QM_Collectors::get( 'doing_it_wrong' ) );
			$this->log_collector_entries( \QM_Collectors::get( 'db_queries' ) );
		}

		public function log_collector_entries( ?\QM_DataCollector $collector ): void {
			if ( is_null( $collector ) ) {
				return;
			}

			$data       = $collector->get_data();
			$to_collect = null;

			if ( $collector instanceof \QM_Collector_PHP_Errors ) {
				$to_collect = $data->errors;
				if ( empty( $to_collect ) ) {
					return;
				}

				$log = $this->collect_php_data( $to_collect );
			} elseif ( $collector instanceof \QM_Collector_Doing_It_Wrong ) {
				$to_collect = $data->actions;

				if ( empty( $to_collect ) ) {
					return;
				}

				$log = $this->collect_doing_it_wrong_data( $to_collect );
			} elseif ( $collector instanceof \QM_Collector_DB_Queries ) {
				$to_collect = $data->errors;

				if ( empty( $to_collect ) ) {
					return;
				}

				$log = $this->collect_db_queries_data( $to_collect );
			}


			$now       = time();
			$url_hash  = $this->url_hash( $_SERVER['REQUEST_URI'] );
			$file_path = $this->logs_dir . $url_hash . '_' . $now . '_' . rand() . '.json';

			$this->write_file( $file_path, json_encode( $log ) );
		}

		public function collect_php_data( $data ) {
			$log = [];
			foreach ( $data as $level => $level_errors ) {
				if ( ! array_key_exists( $level, $log ) ) {
					$log[ $level ] = [];
				}
				foreach ( $level_errors as $id => $error ) {
					$error_level             = [];
					$error_level['message']  = $error['message'];
					$error_level['line']     = $error['line'];
					$error_level['filename'] = $error['filename'];
					$error_level['file']     = $error['file'];
					$error_level['url']      = $_SERVER['REQUEST_URI'];
					$error_level['trace']    = [];
					if (
						array_key_exists( 'filtered_trace', $error ) &&
						is_array( $error['filtered_trace'] )
					) {
						foreach ( $error['filtered_trace'] as $trace ) {
							$error_level['trace'][] = [
								'file'         => $trace['file'],
								'display'      => $trace['display'],
								'id'           => $trace['id'],
								'line'         => $trace['line'],
								'calling_file' => $trace['calling_file'],
								'calling_line' => $trace['calling_line'],
							];
						}
					}
					$log[ $level ][] = $error_level;
				}
			}

			return $log;
		}

		public function collect_doing_it_wrong_data( $data ) {
			$log = [
				'other' => [],
			];
			foreach ( $data as $id => $error ) {
				$error_level             = [];

				if ( ! empty( $error['component'] ) && $error['component'] instanceof \QM_Component ) {
					if ( $error['component']->type === 'plugin' && $error['component']->context === 'woocommerce' ) {
						// Ignore Doing it Wrong errors coming from Woo Core itself.
						continue;
					}
				}

				$error_level['message'] = $error['message'];
				$error_level['line']     = '';
				$error_level['filename'] = '';
				$error_level['file']     = '';
				$error_level['url']      = $_SERVER['REQUEST_URI'];
				if (
					array_key_exists( 'filtered_trace', $error ) &&
					is_array( $error['filtered_trace'] )
				) {
					foreach ( $error['filtered_trace'] as $trace ) {
						$error_level['trace'][] = [
							'file'         => $trace['file'] ?? '',
							'display'      => $trace['display'] ?? '',
							'id'           => $trace['id'] ?? '',
							'line'         => $trace['line'] ?? '',
							'calling_file' => $trace['calling_file'] ?? '',
							'calling_line' => $trace['calling_line'] ?? '',
						];
					}
				}
				$log['other'][] = $error_level;
			}

			return $log;
		}

		/**
		 * It's an array of WP_Error objects.
		 */
		public function collect_db_queries_data( $data ) {
			$log = [
				'wordpress_db_errors' => [],
			];
			/** @var \WP_Error|array $error */
			foreach ( $data as $error ) {
				$error_level = [];

				if ( $error instanceof \WP_Error ) {
					$error_level['message'] = sprintf( 'WP_Error: %s (%s)', $error->get_error_message(), $error->get_error_code() );
				} else {
					if ( is_array( $error ) && array_key_exists( 'component', $error ) && $error['component'] instanceof \QM_Component ) {
						if ( $error['component']->type === 'plugin' && $error['component']->context === 'woocommerce' ) {
							// Ignore Database errors coming from Woo Core itself.
							continue;
						}
					}

					/*
					 * When invoking dbDelta for the first time, WordPress Core throws a DB error
					 * on wp-admin/includes/upgrade.php by attempting to do a "DESCRIBE" first.
					 * We can safely ignore this error.
					 */
					if ( is_array( $error ) && array_key_exists( 'sql', $error ) ) {
						if ( str_starts_with( $error['sql'], 'DESCRIBE' ) ) {
							if ( ! empty( $error['result'] ) && $error['result'] instanceof \WP_Error ) {
								foreach ( $error['result']->get_error_codes() as $code ) {
									// 1146 = "table does not exist".
									if ( $code === 1146 ) {
										continue 2;
									}
								}
							}
						}
					}

					$error_level['message'] = sprintf( json_encode( $error ) );
				}

				$error_level['line']          = '';
				$error_level['filename']      = '';
				$error_level['file']          = '';
				$error_level['url']           = $_SERVER['REQUEST_URI'];
				$error_level['trace']         = [];
				$log['wordpress_db_errors'][] = $error_level;
			}

			return $log;
		}

		function url_hash( string $url ): string {
			return md5( $url );
		}

		public function write_file( $path, $contents ) {
			$dir_path = dirname( $path );

			if ( ! file_exists( $dir_path ) ) {
				mkdir( $dir_path, 0777, true );
			}
			file_put_contents( $path, $contents );
		}
	}
}

if ( class_exists( 'QM_Collectors' ) ) {
	QM_Error_Summary::init();
}
