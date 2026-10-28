<?php

use CI_CLI\Commands\PhpstanRegressionDiffCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PhpstanRegressionDiffCommandTest extends \PHPUnit\Framework\TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/qit-phpstan-diff-' . uniqid( '', true );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/*' ) ?: [] as $path ) {
			unlink( $path );
		}
		rmdir( $this->directory );
	}

	public function test_invalid_report_is_an_actionable_structured_analysis_failure(): void {
		$baseline_path = $this->directory . '/phpstan-baseline.json';
		$target_path   = $this->directory . '/phpstan-target.json';
		$output_path   = $this->directory . '/result.json';
		file_put_contents( $baseline_path, '{invalid' );
		file_put_contents( $target_path, '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}' );

		$tester = new CommandTester( new PhpstanRegressionDiffCommand() );
		$this->assertSame( Command::FAILURE, $tester->execute( [
			'baseline-report' => $baseline_path,
			'target-report'   => $target_path,
			'--output'        => $output_path,
		] ) );

		$result = json_decode( (string) file_get_contents( $output_path ), true );
		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( 'analysis', $result['failure']['stage'] );
		$this->assertSame( 'phpstan_report_invalid', $result['failure']['code'] );
		$this->assertSame( 'Invalid PHPStan report JSON: phpstan-baseline.json', $result['failure']['message'] );
		$this->assertSame( $result['failure']['message'], $result['reason'] );
		$this->assertStringNotContainsString( $this->directory, (string) file_get_contents( $output_path ) );
		$this->assertGreaterThan( 0, $result['diagnostics']['baseline']['bytes'] );
		$this->assertSame( 64, strlen( $result['diagnostics']['baseline']['sha256'] ) );
	}

	public function test_incomplete_schema_is_unavailable(): void {
		$result = $this->run_diff(
			'{"files":{},"errors":[]}',
			'{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'
		);

		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( 'phpstan_report_invalid_schema', $result['failure']['code'] );
		$this->assertStringContainsString( 'missing totals', $result['failure']['message'] );
	}

	public function test_incoherent_file_totals_are_unavailable(): void {
		$result = $this->run_diff(
			'{"totals":{"errors":0,"file_errors":1},"files":{},"errors":[]}',
			'{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'
		);

		$this->assertSame( 'phpstan_report_invalid_schema', $result['failure']['code'] );
		$this->assertStringContainsString( 'incoherent totals.file_errors', $result['failure']['message'] );
	}

	public function test_top_level_error_is_sanitized_and_unavailable(): void {
		$result = $this->run_diff(
			'{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Failure at https://example.test/report?token=secret Authorization: bearer-secret"]}',
			'{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'
		);

		$this->assertSame( 'phpstan_top_level_error', $result['failure']['code'] );
		$this->assertStringContainsString( '[redacted-url]', $result['failure']['message'] );
		$this->assertStringNotContainsString( 'example.test', json_encode( $result ) );
		$this->assertStringNotContainsString( 'bearer-secret', json_encode( $result ) );
	}

	public function test_fatal_phpstan_exit_is_unavailable_with_runtime_metadata(): void {
		$result = $this->run_diff(
			'{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}',
			'',
			[
				'--baseline-exit-code'    => '0',
				'--target-exit-code'      => '2',
				'--requested-php-version' => '7.4',
				'--actual-php-version'    => '7.4.33',
				'--php-extensions-json'   => '["curl","zip"]',
				'--phpstan-version'       => 'PHPStan 2.1.0',
			]
		);

		$this->assertSame( 'phpstan_pass_failed', $result['failure']['code'] );
		$this->assertSame( 2, $result['metadata']['analysis_exit_codes']['target'] );
		$this->assertSame( '7.4', $result['metadata']['runtime']['requested_php_version'] );
		$this->assertSame( '7.4.33', $result['metadata']['runtime']['actual_php_version'] );
		$this->assertSame( [ 'curl', 'zip' ], $result['metadata']['runtime']['php_extensions'] );
		$this->assertSame( 0, $result['diagnostics']['target']['bytes'] );
	}

	public function test_phpstan_level_controls_missing_return_policy(): void {
		$baseline_path = $this->directory . '/phpstan-baseline.json';
		$target_path   = __DIR__ . '/data/phpstan-return-missing.json';
		$output_path   = $this->directory . '/result.json';
		file_put_contents( $baseline_path, '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}' );

		$tester = new CommandTester( new PhpstanRegressionDiffCommand() );
		$this->assertSame( Command::SUCCESS, $tester->execute( [
			'baseline-report'      => $baseline_path,
			'target-report'        => $target_path,
			'--phpstan-level'      => '0',
			'--output'             => $output_path,
			'--fail-on-introduced' => true,
		] ) );

		$result = json_decode( (string) file_get_contents( $output_path ), true );
		$this->assertSame( 0, $result['metadata']['phpstan_level'] );
		$this->assertSame( 0, $result['summary']['target_count'] );
		$this->assertSame( 0, $result['summary']['introduced_count'] );

		$this->assertSame( Command::FAILURE, $tester->execute( [
			'baseline-report'      => $baseline_path,
			'target-report'        => $target_path,
			'--phpstan-level'      => '1',
			'--output'             => $output_path,
			'--fail-on-introduced' => true,
		] ) );

		$result = json_decode( (string) file_get_contents( $output_path ), true );
		$this->assertSame( 1, $result['metadata']['phpstan_level'] );
		$this->assertSame( 1, $result['summary']['target_count'] );
		$this->assertSame( 1, $result['summary']['introduced_count'] );
	}

	/** @param array<string,string> $options */
	private function run_diff( string $baseline, string $target, array $options = [] ): array {
		$baseline_path = $this->directory . '/phpstan-baseline.json';
		$target_path   = $this->directory . '/phpstan-target.json';
		$output_path   = $this->directory . '/result.json';
		file_put_contents( $baseline_path, $baseline );
		file_put_contents( $target_path, $target );

		$tester = new CommandTester( new PhpstanRegressionDiffCommand() );
		$this->assertSame( Command::FAILURE, $tester->execute( array_merge( [
			'baseline-report' => $baseline_path,
			'target-report'   => $target_path,
			'--output'        => $output_path,
		], $options ) ) );

		return json_decode( (string) file_get_contents( $output_path ), true );
	}
}
