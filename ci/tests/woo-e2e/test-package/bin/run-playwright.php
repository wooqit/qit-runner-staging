<?php

namespace QIT\WooE2E;

final class WooE2ERunner {
	const DEFAULT_TIMEOUT_SECONDS = 3300;
	const KILL_AFTER_SECONDS      = 240;
	const TIMEOUT_EXIT_CODE       = 124;
	const TIMEOUT_TEST_NAME       = 'Woo E2E test suite exceeded its execution limit';

	/**
	 * Run Playwright with a soft timeout that leaves time for QIT to collect and report results.
	 *
	 * @param array<string> $arguments Additional Playwright arguments.
	 * @return int
	 */
	public static function run( array $arguments ): int {
		$timeout_seconds = self::get_timeout_seconds();
		$timeout_binary  = getenv( 'QIT_TIMEOUT_BINARY' ) ?: '/usr/bin/timeout';
		$execution_plan  = self::create_execution_plan( $arguments, $timeout_seconds, $timeout_binary );
		$command         = $execution_plan['command'];

		if ( ! $execution_plan['soft_timeout'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI diagnostic output.
			echo "Soft timeout binary not found at {$timeout_binary}; running Playwright without a soft timeout.\n";
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI diagnostic output.
		echo "Running command: {$command}\n";

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru
		passthru( $command, $exit_code );

		if ( $execution_plan['soft_timeout'] && $exit_code === self::TIMEOUT_EXIT_CODE ) {
			$ctrf_path = dirname( __DIR__ ) . '/test-results/ctrf-report.json';
			self::mark_timeout( $ctrf_path, $timeout_seconds );
			echo sprintf(
				"Woo E2E test suite timed out after %d minutes. Partial results were preserved for reporting.\n",
				(int) ceil( $timeout_seconds / 60 )
			);
		}

		return $exit_code;
	}

	/**
	 * Build the command available on the current host.
	 *
	 * @param array<string> $arguments Additional Playwright arguments.
	 * @return array{command:string,soft_timeout:bool}
	 */
	public static function create_execution_plan( array $arguments, int $timeout_seconds, string $timeout_binary = '/usr/bin/timeout' ): array {
		if ( ! is_file( $timeout_binary ) || ! is_executable( $timeout_binary ) ) {
			return [
				'command'      => self::build_playwright_command( $arguments ),
				'soft_timeout' => false,
			];
		}

		return [
			'command'      => self::build_command( $arguments, $timeout_seconds, $timeout_binary ),
			'soft_timeout' => true,
		];
	}

	/**
	 * @param array<string> $arguments Additional Playwright arguments.
	 */
	public static function build_command( array $arguments, int $timeout_seconds, string $timeout_binary = '/usr/bin/timeout' ): string {
		if ( $timeout_seconds < 1 ) {
			throw new \InvalidArgumentException( 'The Woo E2E timeout must be greater than zero.' );
		}

		$command = [
			escapeshellarg( $timeout_binary ),
			'--signal=INT',
			'--kill-after=' . self::KILL_AFTER_SECONDS . 's',
			escapeshellarg( $timeout_seconds . 's' ),
			self::build_playwright_command( $arguments ),
		];

		return implode( ' ', $command );
	}

	/**
	 * @param array<string> $arguments Additional Playwright arguments.
	 */
	public static function build_playwright_command( array $arguments ): string {
		$command = [
			'npx',
			'playwright',
			'test',
			'--project=e2e',
		];

		foreach ( $arguments as $argument ) {
			$command[] = escapeshellarg( $argument );
		}

		return implode( ' ', $command );
	}

	public static function mark_timeout( string $ctrf_path, int $timeout_seconds ): void {
		$report = self::read_ctrf_report( $ctrf_path );
		$tests  = $report['results']['tests'];

		$tests = array_values( array_filter(
			$tests,
			static function ( array $test ): bool {
				return ( $test['name'] ?? '' ) !== self::TIMEOUT_TEST_NAME;
			}
		) );

		$stop_time = (int) round( microtime( true ) * 1000 );
		$tests[]   = [
			'name'      => self::TIMEOUT_TEST_NAME,
			'status'    => 'failed',
			'duration'  => $timeout_seconds * 1000,
			'start'     => $stop_time - ( $timeout_seconds * 1000 ),
			'stop'      => $stop_time,
			'rawStatus' => 'timedout',
			'type'      => 'e2e',
			'filePath'  => 'qit-test.json',
			'message'   => sprintf(
				'The Woo E2E test suite timed out after %d minutes. Results completed before the timeout are included in this report.',
				(int) ceil( $timeout_seconds / 60 )
			),
			'extra'     => [
				'timeout'        => true,
				'timeoutSeconds' => $timeout_seconds,
			],
		];

		$existing_summary = $report['results']['summary'] ?? [];
		if ( ! isset( $existing_summary['start'] ) ) {
			$existing_summary['start'] = $stop_time - ( $timeout_seconds * 1000 );
		}

		$report['results']['tests']   = $tests;
		$report['results']['summary'] = self::summarize( $tests, $existing_summary, $stop_time );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in this standalone CLI script.
		$encoded = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( $encoded === false ) {
			throw new \RuntimeException( "Could not encode the timeout result for {$ctrf_path}." );
		}

		$report_directory = dirname( $ctrf_path );
		if (
			! is_dir( $report_directory )
			&& ! mkdir( $report_directory, 0755, true )
			&& ! is_dir( $report_directory )
		) {
			throw new \RuntimeException( "Could not create the CTRF report directory {$report_directory}." );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- WordPress is not loaded in this standalone CLI script.
		if ( file_put_contents( $ctrf_path, $encoded . "\n" ) === false ) {
			throw new \RuntimeException( "Could not write the timeout result to {$ctrf_path}." );
		}
	}

	private static function get_timeout_seconds(): int {
		$value = getenv( 'QIT_E2E_TEST_TIMEOUT_SECONDS' );
		if ( $value === false || $value === '' ) {
			return self::DEFAULT_TIMEOUT_SECONDS;
		}

		if ( ! ctype_digit( $value ) || (int) $value < 1 ) {
			throw new \InvalidArgumentException( 'QIT_E2E_TEST_TIMEOUT_SECONDS must be a positive integer.' );
		}

		return (int) $value;
	}

	/**
	 * @return array{results:array{tool:array{name:string},summary:array<string,mixed>,tests:array<int,array<string,mixed>>}}
	 */
	private static function read_ctrf_report( string $ctrf_path ): array {
		if ( is_readable( $ctrf_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test artifact.
			$decoded = json_decode( (string) file_get_contents( $ctrf_path ), true );
			if (
				is_array( $decoded ) &&
				isset( $decoded['results'] ) &&
				is_array( $decoded['results'] ) &&
				isset( $decoded['results']['tests'] ) &&
				is_array( $decoded['results']['tests'] )
			) {
				if ( ! isset( $decoded['results']['tool'] ) || ! is_array( $decoded['results']['tool'] ) ) {
					$decoded['results']['tool'] = [ 'name' => 'playwright' ];
				}

				return $decoded;
			}
		}

		return [
			'results' => [
				'tool'    => [ 'name' => 'playwright' ],
				'summary' => [],
				'tests'   => [],
			],
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $tests
	 * @param array<string,mixed>            $existing_summary
	 * @return array<string,mixed>
	 */
	private static function summarize( array $tests, array $existing_summary, int $stop_time ): array {
		$summary = array_merge(
			$existing_summary,
			[
				'tests'   => count( $tests ),
				'passed'  => 0,
				'failed'  => 0,
				'pending' => 0,
				'skipped' => 0,
				'other'   => 0,
				'start'   => isset( $existing_summary['start'] ) ? (int) $existing_summary['start'] : $stop_time,
				'stop'    => $stop_time,
			]
		);

		foreach ( $tests as $test ) {
			$status = $test['status'] ?? 'other';
			if ( isset( $summary[ $status ] ) && in_array( $status, [ 'passed', 'failed', 'pending', 'skipped', 'other' ], true ) ) {
				++$summary[ $status ];
			} else {
				++$summary['other'];
			}
		}

		return $summary;
	}
}

if (
	PHP_SAPI === 'cli' &&
	isset( $argv[0] ) &&
	realpath( (string) $argv[0] ) === __FILE__
) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passing through the child process exit code.
	exit( WooE2ERunner::run( array_slice( $argv, 1 ) ) );
}
