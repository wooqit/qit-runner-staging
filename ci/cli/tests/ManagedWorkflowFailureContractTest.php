<?php

use PHPUnit\Framework\TestCase;

class ManagedWorkflowFailureContractTest extends TestCase {
	private string $repository_root;

	protected function setUp(): void {
		parent::setUp();
		$this->repository_root = dirname( __DIR__, 3 );
	}

	/**
	 * @dataProvider generic_result_workflow_provider
	 */
	public function test_generic_result_workflows_capture_and_submit_structured_failures( string $workflow ): void {
		$contents = $this->workflow_contents( $workflow );

		$this->assertStringContainsString( 'run: php src/cli.php download-plugins', $contents );
		$this->assertStringContainsString( 'php src/cli.php notify -r', $contents );
		// Download, entry-point identification, and notify must all share the failure file.
		$this->assertStringContainsString( 'run: php find-plugin-entrypoint.php', $contents );
		$this->assertGreaterThanOrEqual( 3, substr_count( $contents, 'QIT_FAILURE_OUTPUT:' ) );
	}

	public function test_security_workflow_shares_the_failure_file_between_download_entry_point_and_normalizer(): void {
		$contents     = $this->workflow_contents( 'security' );
		$failure_file = 'QIT_FAILURE_OUTPUT: ${{ github.workspace }}/qit-workflow-failure.json';

		$this->assertStringContainsString( 'run: php find-plugin-entrypoint.php', $contents );
		$this->assertSame( 2, substr_count( $contents, $failure_file ) );
		$this->assertStringContainsString( 'DOWNLOAD_FAILURE_FILE: ${{ github.workspace }}/qit-workflow-failure.json', $contents );
	}

	/**
	 * Every workflow step that identifies the entry point must be able to report a
	 * structured failure — otherwise entry-point failures degrade to each workflow's
	 * generic fallback message. This scans all workflows, so a new or previously
	 * missed workflow using the script cannot ship unwired.
	 */
	public function test_every_entry_point_step_in_every_workflow_reports_structured_failures(): void {
		$workflow_files = glob( $this->repository_root . '/.github/workflows/*.yml' );
		$this->assertNotEmpty( $workflow_files );

		// The usage indexer runs every step with continue-on-error and reports outcomes
		// inside its result JSON (DOWNLOAD_OUTCOME/ENTRY_OUTCOME envs); it has no
		// failure-file consumer, so a QIT_FAILURE_OUTPUT there would be dead weight.
		$exempt = [ 'ci-runner-ecosystem-usage-index.yml' ];

		$steps_using_script = 0;

		foreach ( $workflow_files as $workflow_file ) {
			if ( in_array( basename( $workflow_file ), $exempt, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $workflow_file );

			if ( strpos( $contents, 'find-plugin-entrypoint.php' ) === false ) {
				continue;
			}

			// Each "- name:" at step indentation starts a new step block.
			$steps = preg_split( '/^      - name:/m', $contents );

			foreach ( $steps as $step ) {
				if ( strpos( $step, 'find-plugin-entrypoint.php' ) === false ) {
					continue;
				}

				$steps_using_script ++;
				$this->assertStringContainsString(
					'QIT_FAILURE_OUTPUT:',
					$step,
					sprintf(
						'The entry-point step in %s does not pass QIT_FAILURE_OUTPUT to find-plugin-entrypoint.php.',
						basename( $workflow_file )
					)
				);
			}
		}

		// 11 generated workflows + api-fuzz + phpstan-regression.
		$this->assertGreaterThanOrEqual( 13, $steps_using_script );
	}

	/**
	 * The workflow files are regenerated from .github/workflows/scripts/workflows-tweaker.php,
	 * so the failure wiring must live in the generator templates — otherwise the next
	 * regeneration silently strips it from every workflow.
	 */
	public function test_workflow_generator_templates_carry_the_failure_wiring(): void {
		$tweaker = file_get_contents( $this->repository_root . '/.github/workflows/scripts/workflows-tweaker.php' );
		$this->assertIsString( $tweaker );

		$entry_point_template = $this->heredoc_template( $tweaker, '$find_plugin_entrypoint' );
		$this->assertStringContainsString( 'QIT_FAILURE_OUTPUT: ${{ github.workspace }}/qit-workflow-failure.json', $entry_point_template );

		$download_template = $this->heredoc_template( $tweaker, '$download_plugins' );
		$this->assertStringContainsString( 'QIT_FAILURE_OUTPUT: ${{ github.workspace }}/qit-workflow-failure.json', $download_template );
	}

	private function heredoc_template( string $source, string $variable ): string {
		$this->assertSame(
			1,
			preg_match( '/' . preg_quote( $variable, '/' ) . '\s*=\s*<<<\s*\'YML\'\n(.*?)\nYML;/s', $source, $matches ),
			"Could not find the $variable heredoc template in workflows-tweaker.php."
		);

		return $matches[1];
	}

	/**
	 * @dataProvider local_runner_workflow_provider
	 */
	public function test_local_runner_fallback_distinguishes_failure_from_cancellation( string $workflow ): void {
		$contents = $this->workflow_contents( $workflow );

		$this->assertStringContainsString( '- name: Finalize unreported test', $contents );
		$this->assertStringContainsString( 'TEST_RESULT_JSON: qit-workflow-result.json', $contents );
		$this->assertStringContainsString( 'TEST_RESULT: failed', $contents );
		$this->assertStringContainsString( "CANCELLED: \${{ job.status == 'cancelled' }}", $contents );
		$this->assertStringContainsString( 'manager-notified.txt || php src/cli.php notify -r', $contents );
	}

	public function test_performance_runner_does_not_claim_manager_was_notified_before_the_cli_reports(): void {
		$contents = file_get_contents( $this->repository_root . '/ci/tests/performance/run-performance-test.php' );

		$this->assertIsString( $contents );
		$this->assertStringNotContainsString( "file_put_contents( getenv( 'QIT_WRITE_MANAGER_NOTIFIED' )", $contents );
	}

	public function test_woo_e2e_timeout_budgets_leave_time_to_finalize_results(): void {
		$workflow = $this->workflow_contents( 'woo-e2e' );
		$manifest = json_decode(
			(string) file_get_contents( $this->repository_root . '/ci/tests/woo-e2e/test-package/qit-test.json' ),
			true
		);
		$runner   = (string) file_get_contents(
			$this->repository_root . '/ci/tests/woo-e2e/test-package/bin/run-playwright.php'
		);

		$this->assertStringContainsString( 'timeout-minutes: 120', $workflow );
		$this->assertStringContainsString( "E2E_MAX_FAILURES: '30'", $workflow );
		$this->assertStringContainsString( "QIT_E2E_TEST_TIMEOUT_SECONDS: '3300'", $workflow );
		$this->assertStringContainsString( 'KILL_AFTER_SECONDS      = 240', $runner );
		$this->assertIsArray( $manifest );
		$this->assertSame( 'php ./bin/run-playwright.php', $manifest['test']['phases']['run'][0]['command'] );
		$this->assertSame( 'host', $manifest['test']['phases']['run'][0]['runs_on'] );
		$this->assertSame( 3600, $manifest['test']['phases']['run'][0]['timeout'] );
		$this->assertLessThan( 3600, 3300 + 240 );
		$this->assertLessThan( 120 * 60, 3600 );
	}

	/** @return array<string,array{string}> */
	public function generic_result_workflow_provider(): array {
		return [
			'activation'        => [ 'activation' ],
			'compatibility'     => [ 'compatibility' ],
			'malware'           => [ 'malware' ],
			'performance'       => [ 'performance' ],
			'php-compatibility' => [ 'php-compatibility' ],
			'phpstan'           => [ 'phpstan' ],
			'plugin-check'      => [ 'plugin-check' ],
			'validation'        => [ 'validation' ],
			'woo-api'           => [ 'woo-api' ],
			'woo-e2e'           => [ 'woo-e2e' ],
		];
	}

	/** @return array<string,array{string}> */
	public function local_runner_workflow_provider(): array {
		return [
			'activation'    => [ 'activation' ],
			'compatibility' => [ 'compatibility' ],
			'performance'   => [ 'performance' ],
			'woo-api'       => [ 'woo-api' ],
			'woo-e2e'       => [ 'woo-e2e' ],
		];
	}

	private function workflow_contents( string $workflow ): string {
		$contents = file_get_contents( $this->repository_root . "/.github/workflows/ci-runner-$workflow.yml" );
		$this->assertIsString( $contents );

		return $contents;
	}
}
