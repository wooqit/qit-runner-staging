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

		if ( $zip->open( $this->zip_path ) !== true ) {
			throw new PluginDownloadException( "Could not extract {$this->slug}: the plugin archive could not be opened.", 'plugin_archive_unreadable' );
		}

		$root_entrypoint    = null;
		$wrapper_candidates = [];

		for ( $i = 0; $i < $zip->numFiles; $i ++ ) {
			$info = $zip->statIndex( $i );

			if ( ! $info ) {
				throw new PluginDownloadException( "Could not extract {$this->slug}: the plugin archive could not be read.", 'plugin_archive_unreadable' );
			}

			$dir_depth = substr_count( trim( $info['name'], '/' ), '/' );

			if ( $dir_depth > 1 || substr( strtolower( $info['name'] ), - 4 ) !== '.php' ) {
				continue;
			}

			$header_chunk = file_get_contents( "zip://{$this->zip_path}#{$info['name']}", false, null, 0, 8 * 1024 );

			if ( $header_chunk === false || ! FileHeaderMatcher::contains_plugin_header( $header_chunk ) ) {
				continue;
			}

			$this->output->writeln( "Found plugin entry point at {$info['name']}." );

			if ( $dir_depth === 0 ) {
				$root_entrypoint = $root_entrypoint ?? $info['name'];
			} else {
				$parent_dir = dirname( $info['name'] );

				if ( ! isset( $wrapper_candidates[ strtolower( $parent_dir ) ] ) ) {
					$wrapper_candidates[ strtolower( $parent_dir ) ] = $parent_dir;
				}
			}
		}

		$extract_dir = $this->extract_path . '/' . $this->slug;

		if ( isset( $wrapper_candidates[ strtolower( $this->slug ) ] ) ) {
			// An exact slug-matching wrapper is the strongest layout signal. Prefer it over
			// a root-level header, which may belong to a stray bundled plugin file.
			$this->output->writeln( 'Found matching parent directory: ' . $wrapper_candidates[ strtolower( $this->slug ) ] );
			$this->output->writeln( 'Extracting to plugins directory.' );
			$zip->extractTo( $this->extract_path );
		} elseif ( $root_entrypoint !== null ) {
			// A bootstrap at the archive root means a flat archive: every entry belongs inside the slug directory,
			// unless an exact slug-matching wrapper was found.
			$this->output->writeln( "Found {$root_entrypoint} at the archive root. Creating directory {$this->slug} and extracting there." );

			if ( ! @mkdir( $extract_dir ) ) {
				throw new PluginDownloadException(
					"Could not extract {$this->slug}: the destination plugin directory could not be created.",
					'plugin_directory_create_failed'
				);
			}

			$zip->extractTo( $extract_dir );
		} elseif ( $wrapper_candidates !== [] ) {
			$wrapper = reset( $wrapper_candidates );

			if ( count( $wrapper_candidates ) > 1 ) {
				$this->output->writeln(
					sprintf(
						'Found multiple non-matching plugin directories (%s). Selecting the first detected directory: %s.',
						implode( ', ', $wrapper_candidates ),
						$wrapper
					)
				);
			}

			$this->output->writeln( "Extracting to plugins directory with intent to rename $wrapper to {$this->slug}." );
			$zip->extractTo( $this->extract_path );

			if ( ! @rename( $this->extract_path . '/' . $wrapper, $extract_dir ) ) {
				throw new PluginDownloadException(
					"Could not extract {$this->slug}: the extracted plugin directory $wrapper could not be renamed to {$this->slug}.",
					'plugin_directory_rename_failed'
				);
			}

			$this->output->writeln( "Successfully renamed $wrapper to {$this->slug}." );
		} else {
			throw new PluginDownloadException( "Could not extract {$this->slug}: no PHP file with a \"Plugin Name\" header was found in the archive.", 'plugin_entry_point_not_found' );
		}

		$this->output->writeln( "Extraction complete. Extracted to directory {$extract_dir} " );
		$zip->close();
	}

	private function extract_theme() {
		$zip            = new \ZipArchive;
		$header_handler = App::make( HeaderHandler::class );

		if ( $zip->open( $this->zip_path ) !== true ) {
			throw new PluginDownloadException( "Could not extract {$this->slug}: the theme archive could not be opened.", 'theme_archive_unreadable' );
		}

		/*
		 * Theme requirements (style.css header, index.php/templates/index.html, child-theme
		 * state) are tracked per top-level directory, so the selected wrapper is validated
		 * on its own contents — an index.php in an unrelated directory must not vouch for it.
		 */
		$blank_directory = [
			'name'           => '',
			'has_entrypoint' => false,
			'is_child_theme' => false,
			'has_index_php'  => false,
			'has_index_html' => false,
		];
		$directories     = [];

		for ( $i = 0; $i < $zip->numFiles; $i ++ ) {
			$info = $zip->statIndex( $i );

			if ( ! $info ) {
				throw new PluginDownloadException( "Could not extract {$this->slug}: the theme archive could not be read.", 'theme_archive_unreadable' );
			}

			$path       = trim( $info['name'], '/' );
			$dir_depth  = substr_count( $path, '/' );
			$entry_name = basename( $path );

			$stylesheet_directory = null;
			if ( $path === 'style.css' ) {
				$stylesheet_directory = '';
			} elseif ( $dir_depth === 1 && $entry_name === 'style.css' ) {
				$stylesheet_directory = dirname( $path );
			}

			if ( $stylesheet_directory !== null ) {
				$dir_key = strtolower( $stylesheet_directory );

				$directories[ $dir_key ]         = $directories[ $dir_key ] ?? $blank_directory;
				$directories[ $dir_key ]['name'] = $stylesheet_directory;

				$contents = file_get_contents( "zip://{$this->zip_path}#{$info['name']}", false, null, 0, 8 * 1024 );

				if ( $contents === false ) {
					throw new PluginDownloadException(
						"Could not extract {$this->slug}: the theme stylesheet {$info['name']} could not be read.",
						'theme_stylesheet_unreadable'
					);
				}

				if ( $contents === '' ) {
					throw new PluginDownloadException(
						"Could not extract {$this->slug}: the theme stylesheet {$info['name']} is empty.",
						'theme_stylesheet_empty'
					);
				}

				// Only a style.css with a real Theme Name header marks its directory or
				// the archive root as a candidate.
				if ( FileHeaderMatcher::contains_theme_header( $contents ) ) {
					$this->output->writeln( "Found theme entry point at {$info['name']}." );

					$directories[ $dir_key ]['has_entrypoint'] = true;

					$template = $header_handler->fetch_single_plugin_header_item_from_contents( $contents, 'Template' );

					if ( ! empty ( $template ) ) {
						$directories[ $dir_key ]['is_child_theme'] = true;
						$this->output->writeln( "Found child theme. Parent theme is $template." );
					}
				}
			}

			$index_directory = null;
			if ( $path === 'index.php' ) {
				$index_directory = '';
			} elseif ( $dir_depth === 1 && $entry_name === 'index.php' ) {
				$index_directory = dirname( $path );
			}

			if ( $index_directory !== null ) {
				$dir_key = strtolower( $index_directory );

				$directories[ $dir_key ]                  = $directories[ $dir_key ] ?? $blank_directory;
				$directories[ $dir_key ]['name']          = $index_directory;
				$directories[ $dir_key ]['has_index_php'] = true;
			}

			$index_html_directory = null;
			if ( $path === 'templates/index.html' ) {
				$index_html_directory = '';
			} elseif ( $dir_depth === 2 ) {
				$parts = explode( '/', $path, 2 );

				if ( $parts[1] === 'templates/index.html' ) {
					$index_html_directory = $parts[0];
				}
			}

			if ( $index_html_directory !== null ) {
				$dir_key = strtolower( $index_html_directory );

				$directories[ $dir_key ]                   = $directories[ $dir_key ] ?? $blank_directory;
				$directories[ $dir_key ]['name']           = $index_html_directory;
				$directories[ $dir_key ]['has_index_html'] = true;
			}
		}

		$wrapper_candidates = array_filter( $directories, static function ( array $directory ): bool {
			return $directory['has_entrypoint'];
		} );

		if ( $wrapper_candidates === [] ) {
			throw new PluginDownloadException( "Could not extract {$this->slug}: no style.css with a \"Theme Name\" header was found at the archive root or in a top-level theme directory.", 'theme_entry_point_not_found' );
		}

		if ( isset( $wrapper_candidates[ strtolower( $this->slug ) ] ) ) {
			$selected = $wrapper_candidates[ strtolower( $this->slug ) ];
		} elseif ( isset( $wrapper_candidates[''] ) ) {
			$selected = $wrapper_candidates[''];
		} else {
			$selected = reset( $wrapper_candidates );

			if ( count( $wrapper_candidates ) > 1 ) {
				$this->output->writeln(
					sprintf(
						'Found multiple non-matching theme directories (%s). Selecting the first detected directory: %s.',
						implode( ', ', array_column( $wrapper_candidates, 'name' ) ),
						$selected['name']
					)
				);
			}
		}

		if ( ! $selected['is_child_theme'] && ! $selected['has_index_php'] && ! $selected['has_index_html'] ) {
			$selected_location = $selected['name'] === '' ? 'archive root' : "directory {$selected['name']}";
			throw new PluginDownloadException( "Could not extract {$this->slug}: the theme {$selected_location} has neither index.php nor templates/index.html.", 'theme_index_missing' );
		}

		$extract_dir = $this->extract_path . '/' . $this->slug;

		if ( $selected['name'] === '' ) {
			$this->output->writeln( "Found style.css at the archive root. Creating directory {$this->slug} and extracting there." );

			if ( ! @mkdir( $extract_dir ) ) {
				throw new PluginDownloadException(
					"Could not extract {$this->slug}: the destination theme directory could not be created.",
					'theme_directory_create_failed'
				);
			}

			$zip->extractTo( $extract_dir );
		} elseif ( strtolower( $selected['name'] ) === strtolower( $this->slug ) ) {
			$this->output->writeln( 'Found matching parent directory: ' . $selected['name'] );
			$this->output->writeln( 'Extracting to themes directory.' );
			$zip->extractTo( $this->extract_path );
		} else {
			$this->output->writeln( "Extracting to themes directory with intent to rename {$selected['name']} to {$this->slug}." );
			$zip->extractTo( $this->extract_path );

			if ( ! @rename( $this->extract_path . '/' . $selected['name'], $extract_dir ) ) {
				throw new PluginDownloadException(
					"Could not extract {$this->slug}: the extracted theme directory {$selected['name']} could not be renamed to {$this->slug}.",
					'theme_directory_rename_failed'
				);
			}

			$this->output->writeln( "Successfully renamed {$selected['name']} to {$this->slug}." );
		}

		$this->output->writeln( "Extraction complete. Extracted to directory {$extract_dir} " );
		$zip->close();
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
