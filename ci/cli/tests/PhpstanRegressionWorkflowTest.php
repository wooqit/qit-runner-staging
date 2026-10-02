<?php

class PhpstanRegressionWorkflowTest extends \PHPUnit\Framework\TestCase {
	private string $workflow;

	protected function setUp(): void {
		$path           = dirname( __DIR__, 3 ) . '/.github/workflows/ci-runner-phpstan-regression.yml';
		$this->workflow = (string) file_get_contents( $path );
	}

	public function test_stub_generation_uses_tooling_runtime_before_requested_analysis_runtime(): void {
		$checkout         = strpos( $this->workflow, '- name: Checkout code.' );
		$tooling_runtime  = strpos( $this->workflow, '- name: Setup PHP tooling runtime' );
		$generate_stubs   = strpos( $this->workflow, '- name: Generate stubs for SUT' );
		$requested_runtime = strpos( $this->workflow, '- name: Setup requested PHP runtime' );
		$verify           = strpos( $this->workflow, '- name: Verify requested PHP runtime' );
		$analysis         = strpos( $this->workflow, '- name: Run Baseline and Target PHPStan' );

		$this->assertIsInt( $checkout );
		$this->assertIsInt( $tooling_runtime );
		$this->assertIsInt( $generate_stubs );
		$this->assertIsInt( $requested_runtime );
		$this->assertIsInt( $verify );
		$this->assertIsInt( $analysis );
		$this->assertLessThan( $tooling_runtime, $checkout );
		$this->assertLessThan( $generate_stubs, $tooling_runtime );
		$this->assertLessThan( $requested_runtime, $generate_stubs );
		$this->assertLessThan( $verify, $requested_runtime );
		$this->assertLessThan( $analysis, $verify );
		$this->assertStringContainsString( "- name: Setup PHP tooling runtime\n        uses: shivammathur/setup-php@v2\n        with:\n          php-version: '8.3'", $this->workflow );
		$this->assertStringContainsString( 'php-version: ${{ env.PHP_VERSION }}', $this->workflow );
		$this->assertStringContainsString( "extensions: \${{ join(matrix.test_run.environment.php_extensions, ',') }}", $this->workflow );
		$this->assertStringContainsString( 'TOKEN: ${{ secrets.QIT_COMPATIBILITY_GITHUB_TOKEN || secrets.TOKEN }}', $this->workflow );
		$this->assertStringContainsString( 'php_runtime_mismatch', $this->workflow );
		$this->assertStringContainsString( 'stub_generation_error', $this->workflow );
		$this->assertStringContainsString( 'STUB_FAILURE_PATH="$GITHUB_WORKSPACE/ci/tests/phpstan/download-failure.json"', $this->workflow );
		$this->assertStringContainsString( 'if (!is_file($path))', $this->workflow );
		$this->assertStringContainsString( "id: cache-ci-cli\n        if: always()", $this->workflow );
		$this->assertStringContainsString( "if: always() && steps.cache-ci-cli.outputs.cache-hit != 'true'", $this->workflow );
	}

	public function test_runtime_and_pass_diagnostics_are_recorded_in_the_result_contract(): void {
		foreach ( [
			'--baseline-exit-code "$baseline_exit"',
			'--target-exit-code "$target_exit"',
			'--requested-php-version "$PHP_VERSION"',
			'--actual-php-version "${{ steps.verify-runtime.outputs.actual_version }}"',
			'--php-extensions-json',
			'--phpstan-version "$phpstan_version"',
			'--php-version "$PHP_VERSION"',
			'VERIFIED_PHP_EXTENSIONS:',
		] as $required_contract ) {
			$this->assertStringContainsString( $required_contract, $this->workflow );
		}
	}

	public function test_woocommerce_artifacts_are_downloaded_from_release_sources_and_validated_before_caching(): void {
		$download_step = strpos( $this->workflow, '- name: Download WooCommerce Baseline and Target' );
		$analysis_step = strpos( $this->workflow, '- name: Run Baseline and Target PHPStan' );

		$this->assertIsInt( $download_step );
		$this->assertIsInt( $analysis_step );
		$this->assertLessThan( $analysis_step, $download_step );
		foreach ( [
			'id: download-woocommerce',
			'continue-on-error: true',
			'https://downloads.wordpress.org/plugin/woocommerce.${version}.zip',
			'https://github.com/woocommerce/woocommerce/releases/download/${version}/woocommerce.zip',
			'curl --fail --location --retry 3 --retry-all-errors',
			'validate_woocommerce_artifact "$destination"',
			'validate_woocommerce_artifact "$temporary"',
			'unzip -tq "$artifact"',
			'grep -Fxq "woocommerce/woocommerce.php"',
			'mv "$temporary" "$destination"',
			'woocommerce_artifact_invalid',
			'woocommerce_artifact_unavailable',
			'$version === "" ? "WooCommerce artifact failure"',
			'if [ -d "$TEST_TYPE_CACHE_DIR/woocommerce" ]; then',
			"if: steps.download-woocommerce.outcome == 'success' && steps.verify-runtime.outputs.valid == '1'",
		] as $artifact_contract ) {
			$this->assertStringContainsString( $artifact_contract, $this->workflow );
		}
	}

	public function test_ecosystem_usage_is_merged_after_the_result_exists_without_becoming_a_gate(): void {
		$ensure_result = strpos( $this->workflow, '- name: Ensure Compatibility Regression Result JSON Exists' );
		$internal_scan = strpos( $this->workflow, '- name: Audit WooCommerce Internal Namespace Usage' );
		$ecosystem_scan = strpos( $this->workflow, '- name: Index WooCommerce Ecosystem Usage' );
		$send_result   = strpos( $this->workflow, '- name: Send test result' );

		$this->assertIsInt( $ensure_result );
		$this->assertIsInt( $internal_scan );
		$this->assertIsInt( $ecosystem_scan );
		$this->assertIsInt( $send_result );
		$this->assertLessThan( $internal_scan, $ensure_result );
		$this->assertLessThan( $ecosystem_scan, $internal_scan );
		$this->assertLessThan( $send_result, $ecosystem_scan );
		$this->assertStringContainsString( "continue-on-error: true\n        working-directory: ci/cli", $this->workflow );
		foreach ( [
			'ecosystem-usage-scan "$SUT_DIRECTORY"',
			'--consumer-slug "$SUT_SLUG"',
			'--consumer-woo-id "$SUT_WOO_ID"',
			'--consumer-version "$resolved_sut_version"',
			'--artifact-ref-json "$artifact_ref_json"',
			'--artifact-path "$GITHUB_WORKSPACE/ci/${{ matrix.test_run.sut_type }}s/$SUT_SLUG.zip"',
			'--merge-into "$GITHUB_WORKSPACE/ci/tests/phpstan/compat-regression.json"',
		] as $contract ) {
			$this->assertStringContainsString( $contract, $this->workflow );
		}
	}
}
