<?php

namespace CI_CLI\Commands;

use CI_CLI\PluginDownloadException;
use CI_CLI\ZipHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function CI_CLI\validate_env_vars;
use function CI_CLI\qit_decrypt_for_ci;

class DownloadPluginCommand extends Command {
	private array $expected_vars = [
		'PLUGINS_ZIPS',
		'PLUGINS_JSON',
		'THEME_DIRECTORY',
		'PLUGIN_DIRECTORY',
		'CI_ENCRYPTION_KEY',
	];

	protected function configure(): void {
		$this
			->setName( 'download-plugins' )
			->setDescription( 'Download a plugin using a given URL.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$current_slug = '';

		try {
			validate_env_vars( $this->expected_vars );
			$plugins_zips = json_decode( trim( trim( getenv( 'PLUGINS_ZIPS' ) ), "'" ), true );
			$plugins      = json_decode( trim( trim( getenv( 'PLUGINS_JSON' ) ), "'" ), true );
			if ( ! is_array( $plugins_zips ) || ! is_array( $plugins ) ) {
				throw new PluginDownloadException( 'Plugin download setup metadata is invalid.', 'plugin_download_failed', 'setup' );
			}

			foreach ( $plugins as $plugin ) {
				if ( ! is_array( $plugin ) || empty( $plugin['slug'] ) ) {
					throw new PluginDownloadException( 'Plugin download setup metadata is invalid.', 'plugin_download_failed', 'setup' );
				}
				$current_slug = (string) $plugin['slug'];

				// Does this individual job have a zip?
				if ( array_key_exists( 'zip', $plugin ) ) {
					$zip = $plugin['zip'];
				} else {
					// If not, then it must be in the shared matrix.
					if ( array_key_exists( $plugin['slug'], $plugins_zips ) ) {
						$zip = $plugins_zips[ $plugin['slug'] ];
					} else {
						throw new PluginDownloadException( "Could not download {$current_slug}: no artifact URL was provided.", 'plugin_download_failed' );
					}
				}

				/**
				 * For Tests managed by the QIT CLI, no zip url will be sent.
				 * This is because the CLI will handle fetching the zip from the manager.
				 */
				if ( empty( $zip ) ) {
					$output->writeln( "Download deferred to the QIT CLI for {$plugin['slug']}." );
					continue;
				}

				$url        = qit_decrypt_for_ci( $zip );
				$parent_dir = $plugin['type'] === 'plugin' ? getenv( 'PLUGIN_DIRECTORY' ) : getenv( 'THEME_DIRECTORY' );
				$zip_file   = $parent_dir . '/' . $plugin['slug'] . '.zip';
				$contents   = $this->download_contents( $url, $plugin );

				file_put_contents( $zip_file, $contents );

				$zip_handler = new ZipHandler( $zip_file, $plugin['slug'], $plugin['type'], $parent_dir, $output );

				$zip_handler->extract();
			}

			return Command::SUCCESS;
		} catch ( PluginDownloadException $e ) {
			$failure = $e->get_failure();
			$output->writeln( $failure['message'] );
			$this->write_failure( $failure );

			return Command::FAILURE;
		} catch ( \Throwable $e ) {
			$failure = [
				'stage'   => $current_slug === '' ? 'setup' : 'plugin_download',
				'code'    => 'plugin_download_failed',
				'message' => $current_slug === ''
					? 'Plugin download setup failed.'
					: "Could not download {$current_slug}: plugin download failed.",
			];
			$output->writeln( $failure['message'] );
			$this->write_failure( $failure );

			return Command::FAILURE;
		}
	}

	/**
	 * @param array<string,mixed> $plugin
	 */
	private function download_contents( string $url, array $plugin ): string {
		$artifact_ref = $plugin['artifact_ref'] ?? [];

		/*
		 * All-plugins zips live in the private woocommerce/all-plugins repo, so the
		 * raw.githubusercontent.com URL is never directly fetchable from the runner.
		 * Skip the doomed file_get_contents() and go straight to the authenticated
		 * Contents API.
		 */
		if ( is_array( $artifact_ref ) && ( $artifact_ref['source'] ?? '' ) === 'all_plugins' ) {
			return $this->download_all_plugins_artifact( $artifact_ref, (string) $plugin['slug'] );
		}

		$contents = @file_get_contents( $url ); // Silence output so that URL is not leaked.

		if ( $contents !== false ) {
			return $contents;
		}

		throw new PluginDownloadException( "Could not download {$plugin['slug']}: artifact request failed.", 'download_transport_error' );
	}

	/**
	 * @param array<string,mixed> $artifact_ref
	 */
	private function download_all_plugins_artifact( array $artifact_ref, string $slug ): string {
		$token = getenv( 'TOKEN' );
		if ( ! is_string( $token ) || $token === '' ) {
			throw new PluginDownloadException( "Could not download {$slug}: missing GitHub token for all-plugins artifact.", 'all_plugins_token_missing' );
		}

		$repo     = isset( $artifact_ref['repo'] ) ? (string) $artifact_ref['repo'] : '';
		$sha      = isset( $artifact_ref['sha'] ) ? (string) $artifact_ref['sha'] : '';
		$zip_path = isset( $artifact_ref['zip_path'] ) ? (string) $artifact_ref['zip_path'] : '';

		if ( $repo === '' || $sha === '' || $zip_path === '' ) {
			throw new PluginDownloadException( "Could not download {$slug}: incomplete all-plugins artifact metadata.", 'all_plugins_metadata_invalid' );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			throw new PluginDownloadException( "Could not download {$slug}: artifact transport is unavailable.", 'download_transport_error' );
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/contents/%s?%s',
			$this->encode_path( $repo ),
			$this->encode_path( $zip_path ),
			http_build_query( [ 'ref' => $sha ] )
		);

		$response = $this->request_all_plugins_artifact( $url, $token );
		$status   = $response['status'];

		if ( $response['contents'] === false || $status !== 200 ) {
			$code = $this->all_plugins_failure_code( $status, $response['transport_error'] );
			$detail = $response['transport_error'] ? 'transport error' : "HTTP {$status}";
			throw new PluginDownloadException( "Could not download {$slug}: all-plugins artifact request failed ({$detail}).", $code );
		}

		return $response['contents'];
	}

	/**
	 * @return array{contents:string|false,status:int,transport_error:bool}
	 */
	protected function request_all_plugins_artifact( string $url, string $token ): array {
		$curl = curl_init();
		if ( $curl === false ) {
			return [
				'contents'        => false,
				'status'          => 0,
				'transport_error' => true,
			];
		}

		curl_setopt_array( $curl, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT      => 'qit-runner',
			CURLOPT_HTTPHEADER     => [
				'Accept: application/vnd.github.v3.raw',
				"Authorization: Bearer {$token}",
			],
		] );

		$response = curl_exec( $curl );
		$status   = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error    = curl_error( $curl );
		curl_close( $curl );

		return [
			'contents'        => $response,
			'status'          => (int) $status,
			'transport_error' => $response === false || $error !== '',
		];
	}

	private function all_plugins_failure_code( int $status, bool $transport_error ): string {
		if ( $transport_error ) {
			return 'download_transport_error';
		}

		switch ( $status ) {
			case 401:
				return 'all_plugins_authentication_failed';
			case 403:
				return 'all_plugins_forbidden';
			case 404:
				return 'all_plugins_not_found';
			default:
				return 'all_plugins_http_error';
		}
	}

	private function encode_path( string $path ): string {
		return str_replace( '%2F', '/', rawurlencode( trim( $path, '/' ) ) );
	}

	/** @param array{stage:string,code:string,message:string} $failure */
	private function write_failure( array $failure ): void {
		$path = getenv( 'QIT_FAILURE_OUTPUT' );
		if ( ! is_string( $path ) || $path === '' ) {
			return;
		}

		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			return;
		}

		$json = json_encode( $failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return;
		}

		$handle = @fopen( $path, 'x' );
		if ( $handle === false ) {
			return;
		}

		@fwrite( $handle, $json . PHP_EOL );
		fclose( $handle );
	}
}
