<?php

namespace CI_CLI\Commands;

use CI_CLI\InternalUsage\InternalUsageScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InternalUsageScanCommand extends Command {
	protected function configure(): void {
		$this
			->setName( 'internal-usage-scan' )
			->setDescription( 'Audit a plugin source tree for WooCommerce Internal namespace references.' )
			->addArgument( 'source-directory', InputArgument::REQUIRED, 'Plugin source directory to scan.' )
			->addOption( 'php-version', null, InputOption::VALUE_REQUIRED, 'PHP language version to use while parsing source.' )
			->addOption( 'output', 'o', InputOption::VALUE_REQUIRED, 'Write the standalone observation JSON to this path.' )
			->addOption( 'merge-into', null, InputOption::VALUE_REQUIRED, 'Attach the observation to an existing compatibility regression JSON result.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$output_path = $input->getOption( 'output' );
		$merge_path  = $input->getOption( 'merge-into' );

		if ( is_string( $output_path ) && $output_path !== '' && is_string( $merge_path ) && $merge_path !== '' ) {
			$output->writeln( '<error>Use only one of --output or --merge-into.</error>' );
			return Command::FAILURE;
		}

		$observation = ( new InternalUsageScanner() )->scan(
			(string) $input->getArgument( 'source-directory' ),
			(string) ( $input->getOption( 'php-version' ) ?: '' )
		);

		try {
			if ( is_string( $merge_path ) && $merge_path !== '' ) {
				$this->merge_observation( $merge_path, $observation );
				return Command::SUCCESS;
			}

			$json = $this->encode_json( $observation );
			if ( is_string( $output_path ) && $output_path !== '' ) {
				$this->write_file( $output_path, $json );
				return Command::SUCCESS;
			}

			$output->writeln( $json );
			return Command::SUCCESS;
		} catch ( \Throwable $e ) {
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );
			return Command::FAILURE;
		}
	}

	/** @param array<string,mixed> $observation */
	private function merge_observation( string $path, array $observation ): void {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Compatibility regression JSON is not readable: %s', $path ) );
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( sprintf( 'Compatibility regression JSON is invalid: %s', $path ) );
		}

		if ( ! isset( $data['observations'] ) || ! is_array( $data['observations'] ) ) {
			$data['observations'] = [];
		}
		$data['observations']['internal_namespace_usage'] = $observation;

		$this->write_file( $path, $this->encode_json( $data ) );
	}

	/** @param array<string,mixed> $data */
	private function encode_json( array $data ): string {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Unable to encode internal usage JSON.' );
		}

		return $json . PHP_EOL;
	}

	private function write_file( string $path, string $contents ): void {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			throw new \RuntimeException( sprintf( 'Internal usage output directory is not writable: %s', $directory ) );
		}

		$temporary_path = tempnam( $directory, '.qit-internal-usage-' );
		if ( $temporary_path === false ) {
			throw new \RuntimeException( sprintf( 'Unable to create temporary output beside %s.', $path ) );
		}

		if ( file_put_contents( $temporary_path, $contents ) === false || ! rename( $temporary_path, $path ) ) {
			@unlink( $temporary_path );
			throw new \RuntimeException( sprintf( 'Unable to write internal usage JSON: %s', $path ) );
		}
	}
}
