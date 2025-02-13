<?php
/*
 * Plugin Name: Activation Test Mu Plugin
 */
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

		public function log_collector_entries( \QM_DataCollector $collector ): void {
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