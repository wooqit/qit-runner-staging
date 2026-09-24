<?php

use CI_CLI\Results\GenericResults;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

class GenericResultsTestDouble extends GenericResults {
	public function result_json(): string {
		return $this->get_test_result_json();
	}
}

class GenericResultsTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/qit-generic-results-' . uniqid( '', true );
		mkdir( $this->directory );
		putenv( 'QIT_FAILURE_OUTPUT' );
	}

	protected function tearDown(): void {
		putenv( 'QIT_FAILURE_OUTPUT' );
		foreach ( glob( $this->directory . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $this->directory );
		parent::tearDown();
	}

	public function test_preserves_inline_json(): void {
		$this->assertSame( '[]', $this->make_results( '[]' )->result_json() );
	}

	public function test_missing_result_file_becomes_structured_workflow_failure(): void {
		$failure_path = $this->directory . '/failure.json';
		file_put_contents( $failure_path, json_encode( [
			'stage'   => 'plugin_download',
			'code'    => 'artifact_request_failed',
			'message' => "Artifact request failed.\n",
		] ) );
		putenv( "QIT_FAILURE_OUTPUT=$failure_path" );

		$result = json_decode( $this->make_results( 'missing.json' )->result_json(), true );

		$this->assertSame( 'plugin_download', $result['failure']['stage'] );
		$this->assertSame( 'artifact_request_failed', $result['failure']['code'] );
		$this->assertSame( 'Artifact request failed.', $result['failure']['message'] );
	}

	public function test_missing_result_file_uses_safe_generic_failure(): void {
		$result = json_decode( $this->make_results( 'missing.json' )->result_json(), true );

		$this->assertSame( 'analysis', $result['failure']['stage'] );
		$this->assertSame( 'result_artifact_missing', $result['failure']['code'] );
	}

	public function test_empty_result_uses_safe_generic_failure(): void {
		$result = json_decode( $this->make_results( '' )->result_json(), true );

		$this->assertSame( 'result_artifact_missing', $result['failure']['code'] );
	}

	public function test_invalid_result_file_uses_safe_generic_failure(): void {
		file_put_contents( $this->directory . '/invalid.json', '{invalid' );

		$result = json_decode( $this->make_results( 'invalid.json' )->result_json(), true );

		$this->assertSame( 'result_artifact_missing', $result['failure']['code'] );
	}

	public function test_cancelled_run_does_not_become_unavailable(): void {
		$this->assertSame( '[]', $this->make_results( 'missing.json', 'true' )->result_json() );
	}

	private function make_results( string $test_result_json, string $cancelled = 'false' ): GenericResultsTestDouble {
		return new GenericResultsTestDouble(
			'',
			'',
			'',
			'',
			'',
			'',
			$cancelled,
			'failed',
			$this->directory,
			$test_result_json,
			new NullOutput()
		);
	}
}
