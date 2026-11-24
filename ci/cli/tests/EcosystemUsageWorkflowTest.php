<?php

class EcosystemUsageWorkflowTest extends \PHPUnit\Framework\TestCase {
	public function test_index_worker_is_bounded_and_does_not_run_wordpress_or_phpstan(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/ci-runner-ecosystem-usage-index.yml' );

		$this->assertStringContainsString( 'types: [cd-test-ecosystem-usage-index]', $workflow );
		$this->assertStringContainsString( 'max-parallel: 5', $workflow );
		$this->assertStringContainsString( 'ecosystem-usage-scan', $workflow );
		$this->assertStringContainsString( 'download-plugins', $workflow );
		$this->assertStringContainsString( "'{failure: {stage: \$stage, code: \$code, message: \$message}}'", $workflow );
		$this->assertStringContainsString( '"download_failed"', $workflow );
		$this->assertStringContainsString( '"result_missing"', $workflow );
		$this->assertStringContainsString( 'TEST_RESULT_JSON: ecosystem-usage-index.json', $workflow );
		$this->assertStringContainsString( 'WORKSPACE: ${{ github.workspace }}/ci', $workflow );
		$this->assertStringNotContainsString( 'TEST_RESULT_JSON: ${{ github.workspace }}', $workflow );
		$this->assertStringNotContainsString( '.qit-unavailable-sut', $workflow );
		$this->assertStringNotContainsString( 'wp core', $workflow );
		$this->assertStringNotContainsString( 'phpstan analyse', $workflow );
		$this->assertStringNotContainsString( 'upload-artifact', $workflow );
	}
}
