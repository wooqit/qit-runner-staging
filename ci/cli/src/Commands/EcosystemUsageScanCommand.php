<?php

namespace CI_CLI\Commands;

use CI_CLI\EcosystemImpact\EcosystemUsageScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EcosystemUsageScanCommand extends Command {
	protected function configure(): void {
		$this
			->setName( 'ecosystem-usage-scan' )
			->setDescription( 'Index statically resolvable WooCommerce surfaces consumed by a plugin.' )
			->addArgument( 'source-directory', InputArgument::REQUIRED, 'Plugin source directory to scan.' )
			->addOption( 'php-version', null, InputOption::VALUE_REQUIRED, 'PHP language version to use while parsing source.' )
			->addOption( 'consumer-slug', null, InputOption::VALUE_REQUIRED, 'Consumer plugin slug.' )
			->addOption( 'consumer-woo-id', null, InputOption::VALUE_REQUIRED, 'Consumer WooCommerce.com product ID.' )
			->addOption( 'consumer-version', null, InputOption::VALUE_REQUIRED, 'Resolved consumer version.' )
			->addOption( 'artifact-ref-json', null, InputOption::VALUE_REQUIRED, 'Resolved artifact reference JSON.' )
			->addOption( 'artifact-path', null, InputOption::VALUE_REQUIRED, 'Downloaded artifact path used to compute SHA-256.' )
			->addOption( 'output', 'o', InputOption::VALUE_REQUIRED, 'Write standalone observation JSON to this path.' )
			->addOption( 'merge-into', null, InputOption::VALUE_REQUIRED, 'Attach the observation to an existing compatibility regression JSON result.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$output_path = $input->getOption( 'output' );
		$merge_path  = $input->getOption( 'merge-into' );

		if ( is_string( $output_path ) && $output_path !== '' && is_string( $merge_path ) && $merge_path !== '' ) {
			$output->writeln( '<error>Use only one of --output or --merge-into.</error>' );
			return Command::FAILURE;
		}

		try {
			$artifact_ref = $this->decode_artifact_ref( (string) ( $input->getOption( 'artifact-ref-json' ) ?: '' ) );
			$observation  = ( new EcosystemUsageScanner() )->scan(
				(string) $input->getArgument( 'source-directory' ),
				(string) ( $input->getOption( 'php-version' ) ?: '' ),
				[
					'slug'    => (string) ( $input->getOption( 'consumer-slug' ) ?: '' ),
					'woo_id'  => (int) ( $input->getOption( 'consumer-woo-id' ) ?: 0 ),
					'version' => (string) ( $input->getOption( 'consumer-version' ) ?: '' ),
				],
				$artifact_ref,
				(string) ( $input->getOption( 'artifact-path' ) ?: '' )
			);

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

	/** @return array<string,mixed> */
	private function decode_artifact_ref( string $json ): array {
		if ( trim( $json ) === '' ) {
			return [];
		}

		$artifact_ref = json_decode( $json, true );
		if ( ! is_array( $artifact_ref ) || substr( ltrim( $json ), 0, 1 ) !== '{' ) {
			throw new \InvalidArgumentException( 'Artifact reference JSON must decode to an object.' );
		}

		return $artifact_ref;
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
		$data['observations']['ecosystem_usage'] = $observation;

		$this->write_file( $path, $this->encode_json( $data ) );
	}

	/** @param array<string,mixed> $data */
	private function encode_json( array $data ): string {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Unable to encode ecosystem usage JSON.' );
		}

		return $json . PHP_EOL;
	}

	private function write_file( string $path, string $contents ): void {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			throw new \RuntimeException( sprintf( 'Ecosystem usage output directory is not writable: %s', $directory ) );
		}

		$temporary_path = tempnam( $directory, '.qit-ecosystem-usage-' );
		if ( $temporary_path === false ) {
			throw new \RuntimeException( sprintf( 'Unable to create temporary output beside %s.', $path ) );
		}

		if (
			file_put_contents( $temporary_path, $contents ) === false
			|| ! chmod( $temporary_path, 0644 )
			|| ! rename( $temporary_path, $path )
		) {
			@unlink( $temporary_path );
			throw new \RuntimeException( sprintf( 'Unable to write ecosystem usage JSON: %s', $path ) );
		}
	}
}
