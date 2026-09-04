<?php

use CI_CLI\CompatibilityRegressionUnavailableResult;

class CompatibilityRegressionUnavailableResultTest extends \PHPUnit\Framework\TestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/qit-unavailable-result-' . uniqid( '', true );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $this->directory );
		parent::tearDown();
	}

	public function test_preserves_structured_download_failure_in_unavailable_result(): void {
		$failure_path = $this->directory . '/failure.json';
		$result_path  = $this->directory . '/result.json';
		file_put_contents( $failure_path, json_encode( [
			'stage'   => 'plugin_download',
			'code'    => 'all_plugins_authentication_failed',
			'message' => "Could not download gift-cards: all-plugins artifact request failed (HTTP 401).\n",
		] ) );

		$this->assertTrue( CompatibilityRegressionUnavailableResult::write_if_missing( $result_path, $failure_path, [
			'baseline_woocommerce_version' => '10.9.4',
			'target_woocommerce_version'   => '11.0.0-beta.1',
			'sut_version'                  => '1.2.3',
			'runtime'                      => [
				'requested_php_version' => '7.4',
				'actual_php_version'    => '8.3.6',
				'php_extensions'        => [ 'curl' ],
				'phpstan_version'       => 'PHPStan 2.1.0',
			],
		] ) );

		$result = json_decode( (string) file_get_contents( $result_path ), true );
		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( 'all_plugins_authentication_failed', $result['failure']['code'] );
		$this->assertSame( $result['failure']['message'], $result['reason'] );
		$this->assertStringNotContainsString( "\n", $result['failure']['message'] );
		$this->assertSame( '7.4', $result['metadata']['runtime']['requested_php_version'] );
		$this->assertSame( '8.3.6', $result['metadata']['runtime']['actual_php_version'] );
		$this->assertSame( [ 'curl' ], $result['metadata']['runtime']['php_extensions'] );
		$this->assertSame( 'PHPStan 2.1.0', $result['metadata']['runtime']['phpstan_version'] );
	}

	public function test_uses_generic_reason_for_invalid_failure_file_and_does_not_overwrite_result(): void {
		$failure_path = $this->directory . '/failure.json';
		$result_path  = $this->directory . '/result.json';
		file_put_contents( $failure_path, '{invalid' );

		CompatibilityRegressionUnavailableResult::write_if_missing( $result_path, $failure_path, [] );
		$result = json_decode( (string) file_get_contents( $result_path ), true );
		$this->assertSame( 'Compatibility regression workflow did not produce a diff result.', $result['reason'] );
		$this->assertArrayNotHasKey( 'failure', $result );

		file_put_contents( $result_path, '{"state":"observed"}' );
		$this->assertFalse( CompatibilityRegressionUnavailableResult::write_if_missing( $result_path, $failure_path, [] ) );
		$this->assertSame( '{"state":"observed"}', file_get_contents( $result_path ) );
	}
}
