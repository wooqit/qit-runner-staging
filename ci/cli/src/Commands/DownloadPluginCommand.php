<?php

namespace CI_CLI\Commands;

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
		try {
			validate_env_vars( $this->expected_vars );
			$plugins_zips = json_decode( trim( trim( getenv( 'PLUGINS_ZIPS' ) ), "'" ), true );
			$plugins      = json_decode( trim( trim( getenv( 'PLUGINS_JSON' ) ), "'" ), true );

			foreach ( $plugins as $plugin ) {
				// Does this individual job have a zip?
				if ( array_key_exists( 'zip', $plugin ) ) {
					$zip = $plugin['zip'];
				} else {
					// If not, then it must be in the shared matrix.
					if ( array_key_exists( $plugin['slug'], $plugins_zips ) || empty( $plugins_zips[ $plugin['slug'] ] ) ) {
						$zip = $plugins_zips[ $plugin['slug'] ];
					} else {
						echo "No zip URL found for {$plugin['slug']}.";
						die( 1 );
					}
				}

                /**
                 * For Tests managed by the QIT CLI, no zip url will be sent.
                 * This is because the CLI will handle fetching the zip from the manager.
                 */
                if ( empty( $zip ) ) {
                    $output->writeln( "No zip URL found for {$plugin['slug']}." );
                    continue;
                }

				$url        = qit_decrypt_for_ci( $zip );
				$parent_dir = $plugin['type'] === 'plugin' ? getenv( 'PLUGIN_DIRECTORY' ) : getenv( 'THEME_DIRECTORY' );
				$zip_file   = $parent_dir . '/' . $plugin['slug'] . '.zip';
				$contents   = @file_get_contents( $url ); // Silence output so that URL is not leaked.

				if ( $contents === false ) {
					throw new \Exception( "Could not download {$plugin['slug']}." );
				}

				file_put_contents( $zip_file, $contents );

				$zip_handler = new ZipHandler( $zip_file, $plugin['slug'], $plugin['type'], $parent_dir, $output );

				$zip_handler->extract();
			}


			return Command::SUCCESS;
		} catch ( \Exception $e ) {
			$output->writeln( $e->getMessage() );

			return Command::FAILURE;
		}
	}
}