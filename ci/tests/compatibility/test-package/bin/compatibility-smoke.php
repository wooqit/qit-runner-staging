<?php

declare( strict_types=1 );

// Standalone test-package runner: WordPress APIs are not loaded when these probes run.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged

final class CompatibilitySmoke {
	private const WP_PATH = '/var/www/html';

	/** @var array<int,array<string,mixed>> */
	private array $tests = [];

	private int $failed = 0;

	public function run(): int {
		$this->probe_wp_cli();
		$this->probe_activation_stack();
		$this->probe_http_routes();
		$this->probe_debug_log();
		$this->write_ctrf();

		return $this->failed > 0 ? 1 : 0;
	}

	private function probe_wp_cli(): void {
		$started = microtime( true );
		$result  = $this->run_shell( 'wp plugin list --status=active --format=json --path=' . escapeshellarg( self::WP_PATH ) );

		$this->record(
			'wp-cli active plugin inventory',
			$result['exit_code'] === 0,
			$started,
			$result['stdout'],
			$result['stderr']
		);
	}

	private function probe_activation_stack(): void {
		$started = microtime( true );
		$result  = $this->run_shell( 'wp plugin list --status=active --format=json --path=' . escapeshellarg( self::WP_PATH ) );
		if ( $result['exit_code'] !== 0 ) {
			$this->record(
				'requested plugin stack is active',
				false,
				$started,
				$result['stdout'],
				$result['stderr']
			);
			return;
		}

		$active_plugins = json_decode( $result['stdout'], true );
		if ( ! is_array( $active_plugins ) ) {
			$this->record( 'requested plugin stack is active', false, $started, $result['stdout'], 'Unable to parse active plugin list.' );
			return;
		}

		$active_slugs = array_map(
			static fn( array $plugin ): string => (string) ( $plugin['name'] ?? '' ),
			$active_plugins
		);

		$expected = $this->get_expected_stack();
		$missing  = array_values( array_diff( $expected, $active_slugs ) );

		$this->record(
			'requested plugin stack is active',
			empty( $missing ),
			$started,
			'Expected: ' . implode( ', ', $expected ) . PHP_EOL . 'Active: ' . implode( ', ', $active_slugs ),
			empty( $missing ) ? '' : 'Missing active plugins: ' . implode( ', ', $missing )
		);
	}

	private function probe_http_routes(): void {
		$base_url    = $this->get_probe_base_url();
		$host_header = $this->get_site_host_header();
		$routes      = [
			'storefront' => '/',
			'cart'       => '/cart/',
			'checkout'   => '/checkout/',
			'account'    => '/my-account/',
			'admin'      => '/wp-admin/',
			'rest'       => '/wp-json/',
		];

		foreach ( $routes as $name => $path ) {
			$started = microtime( true );
			$result  = $this->http_probe( $base_url, $path, $host_header );

			$this->record(
				"HTTP probe: {$name}",
				$result['status'] > 0 && $result['status'] < 500,
				$started,
				sprintf( "URL: %s\nStatus: %d\n%s", $result['url'], $result['status'], $result['body'] ),
				$result['error']
			);
		}
	}

	private function probe_debug_log(): void {
		$started        = microtime( true );
		$debug_log_path = self::WP_PATH . '/wp-content/debug.log';

		if ( ! file_exists( $debug_log_path ) ) {
			$this->record( 'WordPress debug log has no fatal errors', true, $started, 'debug.log not present', '' );
			return;
		}

		$debug_log = (string) file_get_contents( $debug_log_path );
		$has_fatal = preg_match( '/(?:PHP\s+)?(?:Fatal|Parse) error/i', $debug_log ) === 1;

		$this->record(
			'WordPress debug log has no fatal errors',
			! $has_fatal,
			$started,
			$this->tail( $debug_log ),
			$has_fatal ? 'Fatal or parse error found in debug.log.' : ''
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function get_expected_stack(): array {
		$stack = getenv( 'QIT_PLUGIN_ACTIVATION_STACK' );
		if ( ! is_string( $stack ) || trim( $stack ) === '' ) {
			return [];
		}

		$decoded = json_decode( $stack, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	private function get_probe_base_url(): string {
		$env_id = getenv( 'QIT_ENV_ID' );
		if ( is_string( $env_id ) && $env_id !== '' ) {
			return 'http://qitenvnginx' . $env_id;
		}

		$site_url = getenv( 'QIT_SITE_URL' );
		if ( is_string( $site_url ) && $site_url !== '' ) {
			return rtrim( $site_url, '/' );
		}

		return 'http://localhost';
	}

	private function get_site_host_header(): string {
		$site_url = getenv( 'QIT_SITE_URL' );
		if ( ! is_string( $site_url ) || $site_url === '' ) {
			return '';
		}

		$host = parse_url( $site_url, PHP_URL_HOST );
		$port = parse_url( $site_url, PHP_URL_PORT );
		if ( ! is_string( $host ) || $host === '' ) {
			return '';
		}

		return is_int( $port ) ? "{$host}:{$port}" : $host;
	}

	/**
	 * @return array{status:int,url:string,body:string,error:string}
	 */
	private function http_probe( string $base_url, string $path, string $host_header ): array {
		$url     = rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
		$headers = [];
		if ( $host_header !== '' ) {
			$headers[] = 'Host: ' . $host_header;
		}

		$context = stream_context_create( [
			'http' => [
				'ignore_errors'   => true,
				'timeout'         => 20,
				'follow_location' => 0,
				'header'          => implode( "\r\n", $headers ),
			],
		] );

		$body = @file_get_contents( $url, false, $context );
		$body = $body === false ? '' : (string) $body;

		$response_headers = function_exists( 'http_get_last_response_headers' )
			? http_get_last_response_headers()
			: ( $http_response_header ?? [] );

		$status = 0;
		if ( isset( $response_headers[0] ) && preg_match( '/\s(\d{3})\s/', $response_headers[0], $matches ) ) {
			$status = (int) $matches[1];
		}

		return [
			'status' => $status,
			'url'    => $url,
			'body'   => $this->tail( $body ),
			'error'  => $status === 0 ? 'No HTTP response received.' : '',
		];
	}

	/**
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	private function run_shell( string $command ): array {
		$descriptor_spec = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $command, $descriptor_spec, $pipes, self::WP_PATH );
		if ( ! is_resource( $process ) ) {
			return [
				'exit_code' => 1,
				'stdout'    => '',
				'stderr'    => 'Unable to start process.',
			];
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return [
			'exit_code' => proc_close( $process ),
			'stdout'    => (string) $stdout,
			'stderr'    => (string) $stderr,
		];
	}

	private function record( string $name, bool $passed, float $started, string $stdout, string $stderr ): void {
		$stopped = microtime( true );
		if ( ! $passed ) {
			++$this->failed;
		}

		$this->tests[] = [
			'name'     => $name,
			'status'   => $passed ? 'passed' : 'failed',
			'duration' => (int) round( ( $stopped - $started ) * 1000 ),
			'start'    => (int) round( $started * 1000 ),
			'stop'     => (int) round( $stopped * 1000 ),
			'type'     => 'smoke',
			'stdout'   => array_values( array_filter( explode( "\n", trim( $stdout ) ) ) ),
			'stderr'   => array_values( array_filter( explode( "\n", trim( $stderr ) ) ) ),
		];
	}

	private function write_ctrf(): void {
		$results_dir = __DIR__ . '/../results';
		if ( ! is_dir( $results_dir ) ) {
			mkdir( $results_dir, 0755, true );
		}

		$total  = count( $this->tests );
		$failed = $this->failed;
		$passed = $total - $failed;
		$now    = (int) round( microtime( true ) * 1000 );

		$ctrf = [
			'results' => [
				'tool'    => [
					'name' => 'qit-compatibility-smoke',
				],
				'summary' => [
					'tests'   => $total,
					'passed'  => $passed,
					'failed'  => $failed,
					'pending' => 0,
					'skipped' => 0,
					'other'   => 0,
					'start'   => $this->tests[0]['start'] ?? $now,
					'stop'    => $now,
					'suites'  => 1,
				],
				'tests'   => $this->tests,
			],
		];

		file_put_contents( $results_dir . '/ctrf-report.json', json_encode( $ctrf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	private function tail( string $value, int $limit = 12000 ): string {
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, -1 * $limit );
	}
}

$compatibility_smoke = new CompatibilitySmoke();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer exit status for CLI runner.
exit( $compatibility_smoke->run() );
