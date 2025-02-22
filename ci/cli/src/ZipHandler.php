<?php

namespace CI_CLI;

use Symfony\Component\Console\Output\OutputInterface;

class ZipHandler {
	protected string $zip_path;
	protected string $slug;
	protected string $type;
	protected string $extract_path;
	protected OutputInterface $output;

	public function __construct(
		string $zip_path,
		string $slug,
		string $type,
		string $extract_path,
		OutputInterface $output
	) {
		$this->zip_path     = $zip_path;
		$this->slug         = $slug;
		$this->type         = $type;
		$this->extract_path = $extract_path;
		$this->output       = $output;
	}

	private function extract_plugin() {
		$zip = new \ZipArchive;

		if ( $zip->open( $this->zip_path ) ) {
			$found_matching_parent_directory    = false;
			$found_mismatching_parent_directory = false;
			$found_plugin_entrypoint            = false;
			$mismatching_directory_name         = '';

			/*
		 	 * Iterates over the contents of a zip file to validate that it contains
			 * both the parent directory and the plugin entrypoint.
			 *
			 * To improve efficiency, we use a bidirectional approach. This means we iterate the zip index from both
			 * ends - we search one item from the beginning, then one item from the end, alternating until
			 * we meet in the middle. This approach can be more efficient when the required files are likely
			 * to be near the start or end of the index.
			 */
			$left  = 0;
			$right = $zip->numFiles - 1;

			while ( $left <= $right ) {
				foreach ( [ $left, $right ] as $i ) {
					$info = $zip->statIndex( $i );

					if ( ! $info ) {
						throw new \Exception( 'Cannot read zip index' );
					}

					$dir_depth = substr_count( trim( $info['name'], '/' ), '/' );

					if ( $dir_depth <= 1 && substr( strtolower( $info['name'] ), - 4 ) === '.php' ) {
						if ( stripos( file_get_contents( "zip://{$this->zip_path}#{$info['name']}", false, null, 0, 8 * 1024 ), 'Plugin Name' ) !== false ) {
							$this->output->writeln( "Found plugin entry point at {$info['name']}." );
							$found_plugin_entrypoint = true;

							if ( $dir_depth === 1 ) {
								$current_parent_dir = dirname( $info['name'] );

								if ( strpos( strtolower( $current_parent_dir . '/' ), strtolower( $this->slug . '/' ) ) === 0 ) {
									$found_matching_parent_directory ?: $this->output->writeln("Found matching parent directory: $current_parent_dir" );
									$found_matching_parent_directory = true;
								} else {
									$found_mismatching_parent_directory ?: $this->output->writeln( "Found mismatching parent directory: $current_parent_dir" );
									$found_mismatching_parent_directory = true;
									$mismatching_directory_name         = $current_parent_dir;
								}
							}
						}
					}
				}

				$left ++;
				$right --;
			}

			if ( ! $found_plugin_entrypoint ) {
				throw new \Exception( "Plugin entry point not found in $this->zip_path.", 147 );
			}

			$extract_dir = $this->extract_path . '/' . $this->slug;

			if ( $found_matching_parent_directory ) {
				$this->output->writeln( "Extracting to plugins directory." );
				$zip->extractTo( $this->extract_path );
			} else if ( $found_mismatching_parent_directory ) {
				$this->output->writeln( "Extracting to plugins directory with intent to rename $mismatching_directory_name to {$this->slug}." );
				$zip->extractTo( $this->extract_path );
				if ( rename( $this->extract_path . '/' . $mismatching_directory_name, $this->extract_path . '/' . $this->slug ) ) {
					$this->output->writeln( "Successfully renamed $mismatching_directory_name to {$this->slug}." );
				} else {
					$this->output->writeln( "Failed to rename $mismatching_directory_name to {$this->slug}." );
				}
			} else {
				$this->output->writeln( "Creating directory {$this->slug} and extracting there." );
				if ( ! mkdir( $extract_dir ) ) {
					throw new \Exception( 'Cannot create plugin directory' );
				}
				$zip->extractTo( $extract_dir );
			}

			$this->output->writeln( "Extraction complete. Extracted to directory {$extract_dir} " );
			$zip->close();
		} else {
			throw new \Exception( "Could not open {$this->zip_path}." );
		}
	}

	private function extract_theme() {
		$zip            = new \ZipArchive;
		$header_handler = App::make( HeaderHandler::class );

		if ( $zip->open( $this->zip_path ) ) {
			$found_index_php                    = false;
			$found_index_html                   = false;
			$found_matching_parent_directory    = false;
			$found_mismatching_parent_directory = false;
			$found_theme_entrypoint             = false;
			$mismatching_directory_name         = '';
			$is_child_theme					    = false;

			/*
		 	 * Iterates over the contents of a zip file to validate that it contains
			 * both the parent directory and the plugin entrypoint.
			 *
			 * To improve efficiency, we use a bidirectional approach. This means we iterate the zip index from both
			 * ends - we search one item from the beginning, then one item from the end, alternating until
			 * we meet in the middle. This approach can be more efficient when the required files are likely
			 * to be near the start or end of the index.
			 */
			$left  = 0;
			$right = $zip->numFiles - 1;

			while ( $left <= $right ) {
				foreach ( [ $left, $right ] as $i ) {
					$info = $zip->statIndex( $i );

					if ( ! $info ) {
						throw new \Exception( 'Cannot read zip index' );
					}

					$dir_depth = substr_count( trim( $info['name'], '/' ), '/' );

					if ( $dir_depth == 1 ) {
						$parts = explode( '/', $info['name'] );

						if ( end( $parts ) === 'style.css' ) {
							$found_theme_entrypoint = true;

							$contents = file_get_contents( "zip://{$this->zip_path}#{$info['name']}", false, null, 0, 8 * 1024 );

							if ( empty( $contents ) ) {
								throw new \Exception( "File is empty: zip://{$this->zip_path}#{$info['name']}" );
							}

							$template = $header_handler->fetch_single_plugin_header_item_from_contents( $contents, 'Template' );

							if ( ! empty ( $template ) ) {
								$is_child_theme = true;
								$this->output->writeln( "Found child theme. Parent theme is $template." );
							}
						}

						if ( end( $parts ) === 'index.php' ) {
							$found_index_php = true;
						}

						$current_parent_dir = dirname( $info['name'] );

						if ( strpos( strtolower( $current_parent_dir . '/' ), strtolower( $this->slug . '/' ) ) === 0 ) {
							$found_matching_parent_directory ?: $this->output->writeln("Found matching parent directory: $current_parent_dir" );
							$found_matching_parent_directory = true;
						} else {
							$found_mismatching_parent_directory ?: $this->output->writeln( "Found mismatching parent directory: $current_parent_dir" );
							$found_mismatching_parent_directory = true;
							$mismatching_directory_name         = $current_parent_dir;
						}
					}

					if ( $dir_depth == 2 ) {
						$parts = explode( '/', $info['name'], 2 );

						if ( end( $parts ) === 'templates/index.html' ) {
							$found_index_html = true;
						}
					}
				}

				$left ++;
				$right --;
			}

			if ( ! $found_theme_entrypoint ) {
				throw new \Exception( "Theme entry point not found in $this->zip_path.", 147 );
			}

			if (
				! $is_child_theme &&
				! $found_index_html &&
				! $found_index_php
			) {
				throw new \Exception( "Theme index.php/`templates/index.html` not found in $this->zip_path.", 147 );
			}

			$extract_dir = $this->extract_path . '/' . $this->slug;

			if ( $found_matching_parent_directory ) {
				$this->output->writeln( "Extracting to plugins directory." );
				$zip->extractTo( $this->extract_path );
			} else if ( $found_mismatching_parent_directory ) {
				$this->output->writeln( "Extracting to plugins directory with intent to rename $mismatching_directory_name to {$this->slug}." );
				$zip->extractTo( $this->extract_path );
				if ( rename( $this->extract_path . '/' . $mismatching_directory_name, $this->extract_path . '/' . $this->slug ) ) {
					$this->output->writeln( "Successfully renamed $mismatching_directory_name to {$this->slug}." );
				} else {
					$this->output->writeln( "Failed to rename $mismatching_directory_name to {$this->slug}." );
				}
			} else {
				$this->output->writeln( "Creating directory {$this->slug} and extracting there." );
				if ( ! mkdir( $extract_dir ) ) {
					throw new \Exception( 'Cannot create plugin directory' );
				}
				$zip->extractTo( $extract_dir );
			}

			$this->output->writeln( "Extraction complete. Extracted to directory {$extract_dir} " );
			$zip->close();
		} else {
			throw new \Exception( "Could not open {$this->zip_path}." );
		}
	}

	public function extract() {
		$this->output->writeln( "Extracting {$this->zip_path} with slug {$this->slug}" );

		if ( $this->type === 'plugin' ) {
			$this->extract_plugin();
		} else {
			$this->extract_theme();
		}
	}
}