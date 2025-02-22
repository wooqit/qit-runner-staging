<?php

require_once __DIR__ . '/mimick-request.php';

$compatibility = Compatibility_Test::instance();

$compatibility->validate_env( [
	'SUT_SLUG',
	'SUT_ID',
	'GITHUB_WORKSPACE',
	'TEST_TYPE',
	'SUT_TYPE',
] );

// Debug
$compatibility->exec_in_container( 'wp --info' );
$compatibility->run_compatibility_tests();

if ( $compatibility->tests_executed === 0 ) {
	echo "No tests were executed.\n";
	die( 1 );
}

class Compatibility_Test {
	private static Compatibility_Test $instance;

	/**
	 * @var array The SUT and dependencies. Slug => Zip Path.
	 */
	protected array $suts = [];

	/**
	 * @var array The dependencies to test compatibility with.
	 */
	protected array $extensions_to_test = [];

	protected string $main_sut_slug;
	protected string $main_sut_type;
	protected string $wccom_extension_cache_dir;
	protected string $wporg_extension_cache_dir;
	protected string $sut_dir;
	protected string $woocommerce_dir;
	protected string $results_file;
	protected string $stderr_file;
	protected bool $is_activation_test;
	protected bool $is_compatibility_test;
	protected bool $activating_sut = false;
	protected bool $failed_to_activate_sut = false;

	// Enable to get more verbose output of the test.
	protected bool $debug_mode = false;

	public int $tests_executed = 0;

	protected function __construct() {
		$this->wccom_extension_cache_dir = getenv( 'GITHUB_WORKSPACE' ) . '/ci/all-plugins/product-packages';
		$this->wporg_extension_cache_dir = getenv( 'GITHUB_WORKSPACE' ) . '/ci/cache/wporg-extensions';
		$this->woocommerce_dir           = getenv( 'GITHUB_WORKSPACE' ) . '/ci/cache';
		$this->results_file              = getenv( 'GITHUB_WORKSPACE' ) . '/ci/tests/compatibility/compatibility-results.txt';
		$this->stderr_file               = getenv( 'GITHUB_WORKSPACE' ) . '/ci/tests/compatibility/cd-stderr.txt';
		$this->is_activation_test        = getenv( 'TEST_TYPE' ) === 'activation';
		$this->is_compatibility_test     = getenv( 'TEST_TYPE' ) === 'compatibility';
		$this->main_sut_type			 = getenv( 'SUT_TYPE' );
		$this->sut_dir                   = $this->main_sut_type === 'theme' ? getenv( 'GITHUB_WORKSPACE' ) . '/ci/themes' : getenv( 'GITHUB_WORKSPACE' ) . '/ci/plugins';

		// Debug.
		echo sprintf( "Is activation? %s \nIs compatibility? %s\n", $this->is_activation_test ? 'Yes' : 'No', $this->is_compatibility_test ? 'Yes' : 'No' );

		$this->main_sut_slug = strtolower( getenv( 'SUT_SLUG' ) );

		echo sprintf( "Main SUT slug: %s\n", $this->main_sut_slug );

		$sut = new DirectoryIterator( $this->sut_dir );
		while ( $sut->valid() ) {
			$file = $sut->current();

			if ( $this->is_activation_test ) {
				echo "Processing {$file->getPathname()}\n";
			}

			if ( $file->isFile() && ! $file->isDot() && $file->getExtension() === 'zip' ) {
				$slug = strtolower( $file->getBasename( '.' . $file->getExtension() ) );

				$this->extensions_to_test[ $slug ] = $file->getPathname();

				/*
				 * In Activation Tests, we have to mimick the behavior of Compatibility Tests, where the plugin would
				 * be added twice because it also exists in "all-plugins" or "wporg plugins" folders.
				 */
				if ( $this->is_activation_test && $slug === $this->main_sut_slug ) {
					$this->suts[ $slug ] = $file->getPathname();
				}
			}

			$sut->next();
		}

		try {
			// WCCom cached extension zips are pre-extracted for performance.
			$wccom_cache_dir = new DirectoryIterator( $this->wccom_extension_cache_dir );
			while ( $wccom_cache_dir->valid() ) {
				$file = $wccom_cache_dir->current();

				if ( $file->isDot() ) {
					$file->next();
					continue;
				}

				$plugin_folder_path = sprintf( '%s/%s', $file->getPathname(), $file->getBasename() );

				// Some exceptions that have mixed upper-lower case.
				if ( strpos( $plugin_folder_path, 'woocommerce-gateway-eway-' ) !== false ) {
					$plugin_folder_path = sprintf( '%s/%s', $file->getPathname(), strtolower( $file->getBasename() ) );
				}

				// foo-slug/foo-slug.zip
				if ( $file->isDir() && ! $file->isDot() && file_exists( $plugin_folder_path ) ) {
					$this->extensions_to_test[ strtolower( $file->getBasename() ) ] = $plugin_folder_path;
				} else {
					echo sprintf( 'Skipping %s as we could not find an extension.', $file->getPathname() ) . PHP_EOL;
				}

				$wccom_cache_dir->next();
			}
		} catch( Exception $e ) {
			echo "No additional WCCOM extensions to test.\n";
		}

		try {
			// WPOrg cached extension zips are pre-extracted for performance.
			$wporg_cache_dir = new DirectoryIterator( $this->wporg_extension_cache_dir );
			while ( $wporg_cache_dir->valid() ) {
				$file = $wporg_cache_dir->current();

				// foo-slug.zip
				if ( $file->isDir() && ! $file->isDot() ) {
					$this->extensions_to_test[ strtolower( $file->getBasename() ) ] = $file->getPathname();
				}

				$wporg_cache_dir->next();
			}
		} catch ( Exception $e ) {
			echo "No additional WPORG extensions to test.\n";
		}

		$this->extensions_to_test = array_unique( $this->extensions_to_test );
		asort( $this->extensions_to_test );

		require_once __DIR__ . '/ci-runner.php';

		$response = json_decode( CI_Runner::cd_manager_request( 'get_extensions', [] ), true );

		if ( is_null( $response ) ) {
			echo 'Invalid JSON';
			die( 1 );
		}

		if ( count( $response ) === 0 ) {
			echo 'No extensions returned by the Manager';
			die( 1 );
		}

		echo 'SUTs: ' . count( $this->suts ) . PHP_EOL;
		echo 'Extensions to test (Overall): ' . count( $this->extensions_to_test ) . PHP_EOL;
		echo 'Extensions found in marketplace: ' . count( $response ) . PHP_EOL;

		$this->extensions_to_test = array_intersect_key( $this->extensions_to_test, $response );

		echo 'Extensions to test (In Marketplace): ' . count( $this->extensions_to_test ) . PHP_EOL;

		$missing_extensions = array_diff_key( $response, array_intersect_key( $this->extensions_to_test, $response ) );

		if ( ! empty( $missing_extensions ) ) {
			echo 'Extensions in marketplace that are not in test sample: ' . count( $missing_extensions ) . PHP_EOL;
			$missing = [ 'wccom' => [], 'wporg' => [] ];

			foreach ( $missing_extensions as $slug => $data ) {
				if ( array_key_exists( $data['host'], $missing ) ) {
					$missing[ $data['host'] ][] = $slug;
				}
			}

			if ( $this->is_compatibility_test ) {
				var_dump( $missing );
			}
		}

		// Activate WooCommerce, unless WooCommerce is the SUT.
		if ( count( $this->extensions_to_test ) === 1 && array_key_exists( 'woocommerce', $this->extensions_to_test ) ) {
			// Do not activate WooCommerce, since we are testing it directly.
		} else {
			if ( array_key_exists( 'woocommerce', $this->extensions_to_test ) ) {
				// Remove WooCommerce from the list of extensions to test, since we already activated it.
				unset( $this->extensions_to_test['woocommerce'] );
			}
		}

		// Debug.
		// $this->extensions_to_test = array_slice( $this->extensions_to_test, 50, 200 );
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	public function validate_env( array $required_envs ) {
		$env = getenv();

		foreach ( $required_envs as $required_env ) {
			if ( ! isset( $env[ $required_env ] ) ) {
				echo "Missing required env: $required_env\n";
				die( 1 );
			}
		}
	}

	/**
	 * Passthru will run the command and echo any output it produces.
	 *
	 * @param string $command The command to execute.
	 * @param bool   $stop_if_error
	 * @param array  $allowed_exit_codes
	 * @param array  $force_stop_if_error_code
	 *
	 * @return string The output of the command, if $return_output is true.
	 */
	public function exec_in_container( string $command, bool $stop_if_error = true, array $allowed_exit_codes = [ 0 ], array $force_stop_if_error_code = [] ) {
		$command = sprintf( 'docker exec --tty --user=www-data ci_runner_php_fpm bash -c "%s"', addcslashes( $command, '"' ) );
		if ( $this->debug_mode ) {
			echo "Executing: $command\n";
		}
		exec( $command, $output, $exit_status );

		$exit_status = (int) $exit_status;

		if ( $stop_if_error && ! in_array( $exit_status, $allowed_exit_codes, true ) ) {
			echo "Unexpected exit status code for command $command.\n";
			die( $exit_status );
		}

		// If we receive a very specific exit status code, stop execution even if we said it should continue.
		// This is probably a signal from the error-checker that something is wrong.
		if ( in_array( $exit_status, $force_stop_if_error_code, true ) ) {
			echo "Unexpected exit status code for command $command.\n";
			die( $exit_status );
		}

		return implode( "\n", $output );
	}

	public function exec_in_container_with_exit_code( string $command ): int {
		exec( sprintf( 'docker exec --user=www-data ci_runner_php_fpm bash -c "%s"', addcslashes( $command, '"' ) ), $output, $exit_status );

		return (int) $exit_status;
	}

	public function exec_in_container_and_log_stderr( string $command ): bool {
		$docker_command = sprintf( 'docker exec --user=www-data ci_runner_php_fpm bash -c "%s"', addcslashes( $command, '"' ) );
		if ( $this->debug_mode ) {
			echo "Executing: $docker_command\n";
		}
		$proc           = proc_open( $docker_command, [
			1 => [ 'pipe', 'w' ],  // stdout
			2 => [ 'pipe', 'w' ],  // stderr
		], $pipes );

		$stdout = trim( stream_get_contents( $pipes[1] ) );
		fclose( $pipes[1] );

		$stderr = trim( stream_get_contents( $pipes[2] ) );
		fclose( $pipes[2] );

		$exit_status_code = proc_close( $proc );

		$stdcombined = $stdout . "\n" . $stderr;

		// var_dump( $stdcombined );

		$has_error = false;

		if ( $this->activating_sut && stripos( $stdcombined, 'Failed to activate plugin' ) !== false ) {
			$this->failed_to_activate_sut = true;
		}

		if ( $exit_status_code === 123 || ! empty( $stderr ) || stripos( $stdcombined, 'Failed to activate plugin' ) !== false ) {
			echo "Intercepted output... Error detected.\n";
			#echo "STDOUT:\n";
			#echo substr( $stdout, 0, 500 ) . "\n";
			echo "STDERR:\n";
			echo $stderr . "\n";
			echo "STDCOMBINED:\n";
			echo substr( $stdcombined, 0, 500 ). "\n";

			$results    = json_decode( file_get_contents( $this->results_file ), true ) ?? [];
			$last_error = json_encode( end( $results ) );

			#echo "Last error logged: \n";
			#echo implode( "\n", $last_error ) . "\n";

			$stderr_already_logged = stripos( $last_error, trim( $stderr ) ) !== false;

			if ( $stderr_already_logged ) {
				echo "stderr is already logged.\n";
			} else {
				echo "stderr is not logged. Logging...\n ";
				$has_error = true;

				// Remove WP-CLI backtrace if it exists.
				if ( stripos( $stderr, 'Backtrace:' ) !== false ) {
					$stderr = substr( $stderr, 0, strpos( $stdcombined, 'Backtrace:' ) );
				}

				$written = file_put_contents( $this->stderr_file, '{CD_STDERR}' . trim( str_replace( "\n", '{CD_LINE}', $stderr ) ) );

				if ( $written === false ) {
					echo "Could not open cd-stderr.txt for writing.\n";
					echo "Exists? " . file_exists( $this->stderr_file ) ? 'Yes' : 'No' . PHP_EOL;
					echo "Readable? " . is_readable( $this->stderr_file ) ? 'Yes' : 'No' . PHP_EOL;
					echo "Writable? " . is_writable( $this->stderr_file ) ? 'Yes' : 'No' . PHP_EOL;
					echo $this->exec_in_container( 'ls -la /var/www/html/wp-content/mu-plugins', false );
					die( 1 );
				}

				// Log what is in the stderr file.
				echo $this->exec_in_container( 'php /var/www/html/compatibility-stderr-logger.php', false );

				$results_string = file_get_contents( $this->results_file );

				echo "Results String:\n";
				var_dump( $results_string );

				$results    = json_decode( $results_string, true ) ?? [];

				echo "Results:\n";
				var_dump( $results );

				$last_error = json_encode( end( $results ) );

				echo "Last error logged:\n";
				var_dump( $last_error );
			}
		}

		return $has_error;
	}

	/**
	 * Passthru will run the command and echo any output it produces.
	 *
	 * @param string $command The command to execute.
	 *
	 * @return int The exit status code after running the command.
	 */
	public function exec_in_db_container( string $command, bool $stop_if_error = true, array $allowed_exit_codes = [ 0 ] ) {
		exec( sprintf( 'docker exec --user=root ci_runner_db sh -c "%s"', addcslashes( $command, '"' ) ), $output, $exit_status );

		$exit_status = (int) $exit_status;

		if ( $stop_if_error && ! in_array( $exit_status, $allowed_exit_codes, true ) ) {
			echo "Unexpected exit status code.\n";
			echo sprintf( "Output: %s\n", implode( "\n", $output ) );
			die( $exit_status );
		}

		return $exit_status;
	}

	/**
	 * Runs a query in the DB Container and returns the result.
	 *
	 * @param string $query The query to execute.
	 *
	 * @return array The query result, where each array item is an output.
	 */
	public function run_query_in_db_container( string $query ) {
		exec( sprintf( "docker exec --user=root ci_runner_db sh -c 'echo \"%s\" | mariadb --user=root --password=password --host=ci_runner_db wordpress'", addcslashes( $query, '"' ) ), $output, $exit_status );

		return $output;
	}

	public function run_compatibility_tests() {
		// Early bail: SUT did not activate successfully.
		if ( $this->failed_to_activate_sut ) {
			echo "Bailing further tests because SUT failed to activate.\n";
			return;
		}

		// Set info about tests.
		echo $this->exec_in_container( sprintf( 'wp option add cd_qty_extensions_being_tested "%s"', count( $this->extensions_to_test ) ) ) . PHP_EOL;
		echo $this->exec_in_container( 'wp option add cd_enable_error_logging 1' ) . PHP_EOL;
		echo $this->exec_in_container( sprintf( 'wp option add qit_main_sut_slug "%s"', str_replace( '"', '', $this->main_sut_slug ) ) );

		$this->exec_in_db_container( 'mysqldump --add-drop-table --extended-insert --no-autocommit --user=root --password=password --host=ci_runner_db wordpress > db.sql && ls -lah db.sql && cat db.sql' );
		$overall_timings = [];

		$benchmark = static function ( $start ) {
			return max( 0, number_format( microtime( true ) - $start, 2 ) ) . 's';
		};

		// Create "go-to-frontpage.php" script.
		create_mimick_server_script( __DIR__ . '/go-to-frontpage.php' );
		create_mimick_server_script( __DIR__ . '/go-to-cart.php', 'cart' );
		create_mimick_server_script( __DIR__ . '/go-to-account.php', 'my-account' );
		create_mimick_server_script( __DIR__ . '/go-to-failed.php', 'failed' );

		$count = 0;
		$total = count( $this->extensions_to_test );

		if ( $total === 0 && $this->is_activation_test ) {
			$this->extensions_to_test['activation_test'] = '';
			$total = 1;
		}

		// Everything that happened so far took 2 minutes.
		foreach ( $this->extensions_to_test as $plugin_slug => $plugin_dir_path ) {
			if ( $this->is_activation_test && $plugin_slug !== $this->main_sut_slug ) {
				echo "Skipping $plugin_slug because it's not the SUT ({$this->main_sut_slug}).\n";
				continue;
			}
			// This is where 40 minutes are spent.
			$count ++;
			echo "\n== $plugin_dir_path [$count/$total]==\n";
			$timings = [];

			if ( $plugin_slug !== 'activation_test' ) {
				// Set infos about SUT and extension being activated.
				// $this->exec_in_container( sprintf( 'wp option add cd_activated_alongside "%s"', addcslashes( $plugin_slug, '"' ) ), false );

				$start = microtime( true );
				if ($this->main_sut_type === 'theme') {
					echo 'Activating the theme...' . PHP_EOL;
					$this->exec_in_container_and_log_stderr( sprintf( "wp theme activate \"%s\"", addcslashes( $plugin_slug, '"' ) ) );
					$timings['Theme activate'] = $benchmark( $start );
				} else {
					echo 'Activating the plugin...' . PHP_EOL;
					$this->exec_in_container_and_log_stderr( sprintf( "wp plugin activate \"%s\"", addcslashes( $plugin_slug, '"' ) ) );
					$timings['Plugin activate'] = $benchmark( $start );
				}
			}

			$goto_frontpage   = 'php ' . __DIR__ . '/go-to-frontpage.php';
			$goto_cartpage    = 'php ' . __DIR__ . '/go-to-cart.php';
			$goto_accountpage = 'php ' . __DIR__ . '/go-to-account.php';
			$goto_failedpage  = 'php ' . __DIR__ . '/go-to-failed.php';
			$unlink           = "unlink /var/www/html/wp-content/plugins/$plugin_slug || echo 'Nothing to unlink'";

			if ( $this->main_sut_type === 'plugin' ) {
				echo 'Checking if SUT is active...' . PHP_EOL;
				$start     = microtime( true );
				$output    = $this->run_query_in_db_container( 'SELECT option_value FROM wp_options WHERE option_name="active_plugins"' );
				$activated = false;
				foreach ( $output as $line ) {
					// eg: a:2:{i:0;s:20:"automatewoo/test.php";i:1;s:27:"woocommerce/woocommerce.php";}
					$unserialized = @unserialize( $line );
					if ( is_array( $unserialized ) ) {
						foreach ( $unserialized as $active_plugin ) {
							if ( strpos( $active_plugin, $plugin_slug ) !== false ) {
								$activated = true;
							}
						}
					}
				}
				$timings['Check active plugins'] = $benchmark( $start );
			} else {
				echo 'Checking if SUT is active...' . PHP_EOL;
				$start        = microtime( true );
				$theme_output = $this->run_query_in_db_container( 'SELECT option_value FROM wp_options WHERE option_name="stylesheet"' );
				// ['option_value', 'bistro'];
				$active_theme = isset( $theme_output[1] ) ? $theme_output[1] : '';  // Accessing the second element of the array.

				echo sprintf( "Active theme: %s SUT: %s\n", $active_theme, $this->main_sut_slug );
				$activated = $this->main_sut_slug === $active_theme;

				$timings['Check active theme'] = $benchmark( $start );
			}

			if ( $activated ) {
				$start = microtime( true );
				$this->exec_in_container( $goto_frontpage, false );
				$timings['Go to Front Page'] = $benchmark( $start );

				$start = microtime( true );
				$this->exec_in_container( $goto_cartpage, false );
				$timings['Go to Cart Page'] = $benchmark( $start );

				$start = microtime( true );
				$this->exec_in_container( $goto_accountpage, false );
				$timings['Go to Account Page'] = $benchmark( $start );
			} else {
				$start = microtime( true );
				$this->exec_in_container( $goto_failedpage, false );
				$timings['Go to Failed Page'] = $benchmark( $start );
			}

			if ( $plugin_slug !== 'activation_test' ) {
				if ( strpos( $plugin_dir_path, '.zip' ) === false ) {
					$start = microtime( true );
					$this->exec_in_container( "$unlink", false );
					$timings['Unlink'] = $benchmark( $start );
				}
			}

			$start = microtime( true );
			$this->exec_in_container( 'cd /var/www/html/wp-content/mu-plugins \
			&& find . -type f ! -name "compatibility-tests-error-checker.php" ! -name "compatibility-results.txt" ! -name "cd-stderr.txt" -delete \
			&& cd /var/www/html/wp-content \
			&& find . -maxdepth 1 -type f -delete ' );
			$timings['Remove mu-plugins and drop-ins'] = $benchmark( $start );

			$start = microtime( true );
			echo $this->exec_in_container( "du -h /var/www/html/wp-content/mu-plugins/compatibility-results.txt /var/www/html/wp-content/mu-plugins/cd-stderr.txt && wc -l /var/www/html/wp-content/mu-plugins/compatibility-results.txt", false ) . PHP_EOL;
			$timings['Measure size and line count'] = $benchmark( $start );

			$start = microtime( true );
			echo $this->exec_in_container( sprintf( 'php -r "%s"', "die(is_null(json_decode(file_get_contents('/var/www/html/wp-content/mu-plugins/compatibility-results.txt'), true)) ? 1 : 0);" ) ) . PHP_EOL;
			$timings['Validate JSON'] = $benchmark( $start );

			$start = microtime( true );
			$this->exec_in_db_container( 'mariadb --user=root --password=password --host=ci_runner_db wordpress < db.sql' );
			$timings['Restore DB'] = $benchmark( $start );

			// Debug timings.
			// var_dump( $timings );

			// Compute the sum of all events to make an average
			foreach ( $timings as $event => $timing ) {
				if ( ! array_key_exists( $event, $overall_timings ) ) {
					$overall_timings[ $event ] = (float) $timing;
				} else {
					$overall_timings[ $event ] += (float) $timing;
				}
			}

			$this->tests_executed++;
		}

		@unlink( __DIR__ . '/go-to-failed.php' );
		@unlink( __DIR__ . '/go-to-frontpage.php' );
		@unlink( __DIR__ . '/go-to-cart.php' );
		@unlink( __DIR__ . '/go-to-account.php' );

		echo "OVERALL TIMINGS\n\n";
		var_dump( $overall_timings );
		echo "OVERALL TIMINGS END\n\n";

		echo 'Sut ID: ' . getenv( 'SUT_ID' ) . PHP_EOL;
	}
}