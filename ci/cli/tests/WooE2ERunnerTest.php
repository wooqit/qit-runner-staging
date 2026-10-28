<?php

use PHPUnit\Framework\TestCase;
use QIT\WooE2E\WooE2ERunner;

require_once dirname( __DIR__, 2 ) . '/tests/woo-e2e/test-package/bin/run-playwright.php';

class WooE2ERunnerTest extends TestCase {
	private array $temporary_files       = [];
	private array $temporary_directories = [];

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( array_reverse( $this->temporary_directories ) as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}

		parent::tearDown();
	}

	public function test_builds_a_soft_timeout_command_with_escaped_passthrough_arguments(): void {
		$command = WooE2ERunner::build_command(
			[ '--grep', 'checkout flow' ],
			3300
		);

		$this->assertSame(
			"'/usr/bin/timeout' --signal=INT --kill-after=240s '3300s' npx playwright test --project=e2e '--grep' 'checkout flow'",
			$command
		);
	}

	public function test_uses_the_soft_timeout_when_the_binary_is_available(): void {
		$plan = WooE2ERunner::create_execution_plan(
			[ '--grep', 'checkout flow' ],
			3300,
			PHP_BINARY
		);

		$this->assertTrue( $plan['soft_timeout'] );
		$this->assertSame(
			escapeshellarg( PHP_BINARY ) . " --signal=INT --kill-after=240s '3300s' npx playwright test --project=e2e '--grep' 'checkout flow'",
			$plan['command']
		);
	}

	public function test_runs_playwright_unwrapped_when_the_timeout_binary_is_missing(): void {
		$missing_timeout_binary = sys_get_temp_dir() . '/missing-qit-timeout-' . uniqid( '', true );
		$plan                   = WooE2ERunner::create_execution_plan(
			[ '--grep', 'checkout flow' ],
			3300,
			$missing_timeout_binary
		);

		$this->assertFalse( $plan['soft_timeout'] );
		$this->assertSame(
			"npx playwright test --project=e2e '--grep' 'checkout flow'",
			$plan['command']
		);
	}

	public function test_rejects_a_non_positive_timeout(): void {
		$this->expectException( InvalidArgumentException::class );

		WooE2ERunner::build_command( [], 0 );
	}

	public function test_adds_a_timeout_failure_to_partial_ctrf_results(): void {
		$path            = $this->temporary_path();
		$existing_start  = 1000;
		$existing_report = [
			'results' => [
				'tool'    => [ 'name' => 'playwright' ],
				'summary' => [
					'tests'   => 1,
					'passed'  => 1,
					'failed'  => 0,
					'pending' => 0,
					'skipped' => 0,
					'other'   => 0,
					'start'   => $existing_start,
					'stop'    => 2000,
					'extra'   => [ 'reporter' => 'playwright-ctrf-json-reporter' ],
				],
				'tests'   => [
					[
						'name'     => 'completed test',
						'status'   => 'passed',
						'duration' => 100,
					],
				],
			],
		];
		file_put_contents( $path, json_encode( $existing_report ) );

		WooE2ERunner::mark_timeout( $path, 3300 );

		$report = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( 2, $report['results']['summary']['tests'] );
		$this->assertSame( 1, $report['results']['summary']['passed'] );
		$this->assertSame( 1, $report['results']['summary']['failed'] );
		$this->assertSame( $existing_start, $report['results']['summary']['start'] );
		$this->assertSame( 'playwright-ctrf-json-reporter', $report['results']['summary']['extra']['reporter'] );
		$this->assertSame( 'completed test', $report['results']['tests'][0]['name'] );
		$this->assertSame( WooE2ERunner::TIMEOUT_TEST_NAME, $report['results']['tests'][1]['name'] );
		$this->assertSame( 'timedout', $report['results']['tests'][1]['rawStatus'] );
		$this->assertSame( 3300, $report['results']['tests'][1]['extra']['timeoutSeconds'] );
	}

	public function test_creates_a_valid_timeout_report_when_playwright_did_not_write_one(): void {
		$directory = sys_get_temp_dir() . '/qit-woo-e2e-timeout-' . uniqid( '', true );
		$path      = $directory . '/test-results/ctrf-report.json';

		$this->temporary_directories[] = $directory;
		$this->temporary_directories[] = dirname( $path );
		$this->temporary_files[]       = $path;

		WooE2ERunner::mark_timeout( $path, 3300 );

		$report = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( 'playwright', $report['results']['tool']['name'] );
		$this->assertSame( 1, $report['results']['summary']['tests'] );
		$this->assertSame( 1, $report['results']['summary']['failed'] );
		$this->assertSame( $report['results']['tests'][0]['start'], $report['results']['summary']['start'] );
		$this->assertSame( WooE2ERunner::TIMEOUT_TEST_NAME, $report['results']['tests'][0]['name'] );
	}

	private function temporary_path( bool $create = true ): string {
		$path = sys_get_temp_dir() . '/qit-woo-e2e-timeout-' . uniqid( '', true ) . '.json';
		if ( $create ) {
			touch( $path );
		}
		$this->temporary_files[] = $path;

		return $path;
	}
}
