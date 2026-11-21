<?php

namespace CI_CLI\Commands;

use CI_CLI\Phpstan\PhpstanReport;
use CI_CLI\Phpstan\PhpstanResultDiff;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PhpstanRegressionDiffCommand extends Command {
	protected function configure(): void {
		$this
			->setName( 'phpstan-regression-diff' )
			->setDescription( 'Diff two PHPStan JSON reports and emit introduced errors.' )
			->addArgument( 'baseline-report', InputArgument::REQUIRED, 'Baseline PHPStan JSON report path.' )
			->addArgument( 'target-report', InputArgument::REQUIRED, 'Target PHPStan JSON report path.' )
			->addOption( 'baseline-version', null, InputOption::VALUE_REQUIRED, 'Baseline WooCommerce version.' )
			->addOption( 'target-version', null, InputOption::VALUE_REQUIRED, 'Target WooCommerce version.' )
			->addOption( 'sut-version', null, InputOption::VALUE_REQUIRED, 'Resolved SUT version.' )
			->addOption( 'extension-specs-json', null, InputOption::VALUE_REQUIRED, 'Resolved extension specs JSON.' )
			->addOption( 'artifact-ref', null, InputOption::VALUE_REQUIRED, 'Resolved artifact reference.' )
			->addOption( 'baseline-exit-code', null, InputOption::VALUE_REQUIRED, 'PHPStan baseline process exit code.' )
			->addOption( 'target-exit-code', null, InputOption::VALUE_REQUIRED, 'PHPStan target process exit code.' )
			->addOption( 'requested-php-version', null, InputOption::VALUE_REQUIRED, 'Requested PHP runtime version.' )
			->addOption( 'actual-php-version', null, InputOption::VALUE_REQUIRED, 'Actual PHP runtime version.' )
			->addOption( 'php-extensions-json', null, InputOption::VALUE_REQUIRED, 'Verified PHP extension names as JSON.' )
			->addOption( 'phpstan-version', null, InputOption::VALUE_REQUIRED, 'PHPStan tool version.' )
			->addOption( 'phpstan-level', null, InputOption::VALUE_REQUIRED, 'PHPStan rule level used for both reports.', '0' )
			->addOption( 'strip-prefix', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path prefix to strip before comparing errors.' )
			->addOption( 'output', 'o', InputOption::VALUE_REQUIRED, 'Write diff JSON to this path instead of stdout.' )
			->addOption( 'fail-on-introduced', null, InputOption::VALUE_NONE, 'Exit with failure when introduced errors are found.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$metadata = $this->build_metadata( $input );
		$out_path = $input->getOption( 'output' );
		$diagnostics = [
			'baseline' => PhpstanReport::diagnostics_for_file( (string) $input->getArgument( 'baseline-report' ) ),
			'target'   => PhpstanReport::diagnostics_for_file( (string) $input->getArgument( 'target-report' ) ),
		];

		try {
			$this->validate_exit_codes( $input );
			$strip_prefixes = $input->getOption( 'strip-prefix' );
			if ( ! is_array( $strip_prefixes ) ) {
				$strip_prefixes = [];
			}

			$baseline = PhpstanReport::from_file( (string) $input->getArgument( 'baseline-report' ), $strip_prefixes );
			$target   = PhpstanReport::from_file( (string) $input->getArgument( 'target-report' ), $strip_prefixes );
			$diff     = new PhpstanResultDiff( $baseline, $target, $this->phpstan_level( $input ) );
			$result   = $diff->to_array( $metadata );
			$result['diagnostics'] = $diagnostics;

			$this->write_json( $result, is_string( $out_path ) ? $out_path : '', $output );

			if ( $input->getOption( 'fail-on-introduced' ) && (int) $result['summary']['introduced_count'] > 0 ) {
				return Command::FAILURE;
			}

			return Command::SUCCESS;
		} catch ( \Throwable $e ) {
			$failure = $this->analysis_failure( $e );
			$result = [
				'tool'       => [
					'name'    => 'phpstan-regression-diff',
					'version' => '1',
				],
				'state'      => 'unavailable',
				'reason'     => $failure['message'],
				'failure'    => $failure,
				'metadata'   => $metadata,
				'diagnostics' => $diagnostics,
				'summary'    => [
					'baseline_count'   => 0,
					'target_count'     => 0,
					'introduced_count' => 0,
					'resolved_count'   => 0,
				],
				'introduced' => [],
				'resolved'   => [],
			];

			$this->write_json( $result, is_string( $out_path ) ? $out_path : '', $output );

			return Command::FAILURE;
		}
	}

	/**
	 * @return array{stage:string,code:string,message:string}
	 */
	private function analysis_failure( \Throwable $e ): array {
		if ( preg_match( '/^Invalid PHPStan report JSON:\s*(.+)$/', $e->getMessage(), $matches ) === 1 ) {
			return [
				'stage'   => 'analysis',
				'code'    => 'phpstan_report_invalid',
				'message' => sprintf( 'Invalid PHPStan report JSON: %s', basename( trim( $matches[1] ) ) ),
			];
		}
		if ( strpos( $e->getMessage(), 'Invalid PHPStan report schema:' ) === 0 ) {
			return [
				'stage'   => 'analysis',
				'code'    => 'phpstan_report_invalid_schema',
				'message' => $e->getMessage(),
			];
		}
		if ( strpos( $e->getMessage(), 'PHPStan report contains top-level errors:' ) === 0 ) {
			$message = preg_replace( '/PHPStan report contains top-level errors:\s*[^ ]+/', 'PHPStan report contains top-level errors', $e->getMessage() ) ?? '';
			return [
				'stage'   => 'analysis',
				'code'    => 'phpstan_top_level_error',
				'message' => $message,
			];
		}
		if ( strpos( $e->getMessage(), 'PHPStan pass failed:' ) === 0 ) {
			return [
				'stage'   => 'analysis',
				'code'    => 'phpstan_pass_failed',
				'message' => $e->getMessage(),
			];
		}

		return [
			'stage'   => 'analysis',
			'code'    => 'phpstan_diff_failed',
			'message' => 'PHPStan regression diff could not be completed.',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_metadata( InputInterface $input ): array {
		$metadata = [
			'baseline_woocommerce_version' => (string) ( $input->getOption( 'baseline-version' ) ?: '' ),
			'target_woocommerce_version'   => (string) ( $input->getOption( 'target-version' ) ?: '' ),
			'sut_version'                  => (string) ( $input->getOption( 'sut-version' ) ?: '' ),
			'artifact_ref'                 => (string) ( $input->getOption( 'artifact-ref' ) ?: '' ),
			'phpstan_level'                => $this->phpstan_level( $input ),
		];

		$requested_php_version = (string) ( $input->getOption( 'requested-php-version' ) ?: '' );
		$actual_php_version    = (string) ( $input->getOption( 'actual-php-version' ) ?: '' );
		$phpstan_version       = (string) ( $input->getOption( 'phpstan-version' ) ?: '' );
		$extensions_json       = $input->getOption( 'php-extensions-json' );
		$extensions            = is_string( $extensions_json ) ? json_decode( $extensions_json, true ) : [];
		$metadata['runtime']    = [
			'requested_php_version' => $requested_php_version,
			'actual_php_version'    => $actual_php_version,
			'php_extensions'        => is_array( $extensions ) ? array_values( array_filter( $extensions, 'is_string' ) ) : [],
			'phpstan_version'       => $phpstan_version,
		];
		$metadata['analysis_exit_codes'] = [
			'baseline' => $this->exit_code( $input->getOption( 'baseline-exit-code' ) ),
			'target'   => $this->exit_code( $input->getOption( 'target-exit-code' ) ),
		];

		$extension_specs_json = $input->getOption( 'extension-specs-json' );
		if ( is_string( $extension_specs_json ) && $extension_specs_json !== '' ) {
			$extension_specs = json_decode( $extension_specs_json, true );
			if ( is_array( $extension_specs ) ) {
				$metadata['extension_specs'] = $extension_specs;
			}
		}

		return $metadata;
	}

	private function validate_exit_codes( InputInterface $input ): void {
		$baseline = $this->exit_code( $input->getOption( 'baseline-exit-code' ) );
		$target   = $this->exit_code( $input->getOption( 'target-exit-code' ) );
		if ( ( $baseline !== null && $baseline > 1 ) || ( $target !== null && $target > 1 ) ) {
			throw new \RuntimeException( sprintf(
				'PHPStan pass failed: baseline exit %s, target exit %s.',
				$baseline === null ? 'missing' : (string) $baseline,
				$target === null ? 'missing' : (string) $target
			) );
		}
	}

	/** @param mixed $value */
	private function exit_code( $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	private function phpstan_level( InputInterface $input ): int {
		$level = $input->getOption( 'phpstan-level' );

		return is_numeric( $level ) ? (int) $level : 0;
	}

	/**
	 * @param array<mixed> $result
	 */
	private function write_json( array $result, string $out_path, OutputInterface $output ): void {
		$json = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Unable to encode compatibility regression diff JSON.' );
		}

		if ( $out_path !== '' ) {
			if ( file_put_contents( $out_path, $json . PHP_EOL ) === false ) {
				throw new \RuntimeException( sprintf( 'Unable to write compatibility regression diff JSON: %s', $out_path ) );
			}

			return;
		}

		$output->writeln( $json );
	}
}
