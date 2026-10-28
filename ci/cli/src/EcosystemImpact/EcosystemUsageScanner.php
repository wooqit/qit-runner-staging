<?php

namespace CI_CLI\EcosystemImpact;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

class EcosystemUsageScanner {
	private const SCHEMA_VERSION = 2;

	private const TOOL_VERSION = '5';

	private const MAX_RECORDED_EXCLUDED_DIRECTORIES = 100;

	private const EXCLUDED_DIRECTORIES = [
		'.bzr',
		'.git',
		'.hg',
		'.svn',
		'docs',
		'examples',
		'node_modules',
		'spec',
		'specs',
		'test',
		'tests',
	];

	private const PHP_EXTENSIONS = [ 'inc', 'php', 'phtml' ];

	/**
	 * @param array<string,mixed> $consumer
	 * @param array<string,mixed> $artifact_ref
	 * @return array<string,mixed>
	 */
	public function scan(
		string $source_directory,
		string $php_version = '',
		array $consumer = [],
		array $artifact_ref = [],
		string $artifact_path = ''
	): array {
		$metadata = $this->metadata( $php_version, $consumer, $artifact_ref, $artifact_path );
		$root     = realpath( $source_directory );
		if ( $root === false || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return $this->unavailable(
				sprintf( 'Ecosystem usage source directory is not readable: %s', $source_directory ),
				'sut_unavailable',
				$metadata
			);
		}

		try {
			$discovery = $this->source_files( $root );
		} catch ( \Throwable $e ) {
			return $this->unavailable(
				sprintf( 'Unable to enumerate PHP source files in %s: %s', $source_directory, $e->getMessage() ),
				'scan_error',
				$metadata
			);
		}

		$files              = $discovery['files'];
		$exclusion_coverage = [
			'excluded_directory_count'       => $discovery['excluded_directory_count'],
			'excluded_directories'           => $discovery['excluded_directories'],
			'excluded_directories_truncated' => $discovery['excluded_directories_truncated'],
		];

		if ( empty( $files ) ) {
			return $this->unavailable(
				sprintf( 'No readable PHP source files were found in %s.', $source_directory ),
				'no_php_files',
				$metadata,
				$exclusion_coverage
			);
		}

		try {
			$parser_version          = $this->parser_version( $php_version );
			$parser                  = ( new ParserFactory() )->createForVersion( $parser_version );
			$metadata['php_version'] = $this->display_version( $parser_version );
		} catch ( \Throwable $e ) {
			return $this->unavailable(
				sprintf( 'Invalid requested PHP parser version: %s', $php_version ),
				'invalid_php_version',
				$metadata,
				array_merge(
					$exclusion_coverage,
					[ 'files_discovered' => count( $files ) ]
				)
			);
		}

		$usages             = [];
		$declared_symbols   = [];
		$declared_functions = [];
		$parse_failures     = [];
		$files_scanned      = 0;

		foreach ( $files as $file ) {
			$relative_file = $this->relative_path( $root, $file );
			$code          = file_get_contents( $file );
			if ( $code === false ) {
				$parse_failures[] = [
					'file'    => $relative_file,
					'message' => 'File could not be read.',
				];
				continue;
			}

			try {
				$ast = $parser->parse( $code );
				if ( $ast === null ) {
					throw new \RuntimeException( 'Parser returned no syntax tree.' );
				}

				$visitor   = new EcosystemUsageVisitor( $relative_file, $this->origin( $relative_file ) );
				$traverser = new NodeTraverser();
				$traverser->addVisitor( new NameResolver() );
				$traverser->addVisitor( $visitor );
				$traverser->traverse( $ast );
				$usages = array_merge( $usages, $visitor->get_usages() );
				foreach ( $visitor->get_declared_symbols() as $declared_symbol ) {
					$surface_key = SurfaceNormalizer::surface_key( $declared_symbol );
					if ( $surface_key !== '' ) {
						$declared_symbols[ $surface_key ] = true;
					}
				}
				foreach ( $visitor->get_declared_functions() as $declared_function ) {
					$surface_key = SurfaceNormalizer::surface_key( $declared_function, 'function' );
					if ( $surface_key !== '' ) {
						$declared_functions[ $surface_key ] = true;
					}
				}
				$files_scanned ++;
			} catch ( \Throwable $e ) {
				$parse_failures[] = [
					'file'    => $relative_file,
					'message' => $e->getMessage(),
				];
			}
		}

		$usages = array_values( array_filter(
			$usages,
			static function ( array $usage ) use ( $declared_symbols, $declared_functions ): bool {
				if ( $usage['usage_kind'] === 'function' ) {
					return ! isset( $declared_functions[ $usage['surface_key'] ] );
				}

				return ! isset( $declared_symbols[ $usage['surface_key'] ] );
			}
		) );
		$usages = $this->normalize_usages( $usages );
		usort( $parse_failures, static function ( array $left, array $right ): int {
			return strcmp( $left['file'], $right['file'] );
		} );

		$surfaces   = [];
		$origins    = [
			'extension' => 0,
			'bundled'   => 0,
		];
		$occurrences = [
			'extension' => 0,
			'bundled'   => 0,
		];
		foreach ( $usages as $usage ) {
			$surfaces[ $usage['surface_key'] ] = true;
			$origins[ $usage['origin'] ] ++;
			$occurrences[ $usage['origin'] ] += $usage['count'];
		}

		$state  = empty( $parse_failures )
			? 'observed'
			: ( $files_scanned === 0 ? 'unavailable' : 'partial' );
		$result = [
			'schema_version' => self::SCHEMA_VERSION,
			'tool'           => [
				'name'    => 'qit-ecosystem-usage',
				'version' => self::TOOL_VERSION,
			],
			'metadata'       => $metadata,
			'state'          => $state,
			'coverage'       => [
				'files_discovered'               => count( $files ),
				'files_scanned'                  => $files_scanned,
				'excluded_directory_count'       => $discovery['excluded_directory_count'],
				'excluded_directories'           => $discovery['excluded_directories'],
				'excluded_directories_truncated' => $discovery['excluded_directories_truncated'],
				'parse_failure_count'             => count( $parse_failures ),
				'parse_failures'                 => $parse_failures,
				'complete'                       => empty( $parse_failures ),
			],
			'summary'        => [
				'usage_count'                => count( $usages ),
				'occurrence_count'           => array_sum( $occurrences ),
				'unique_surface_count'       => count( $surfaces ),
				'extension_usage_count'      => $origins['extension'],
				'bundled_usage_count'        => $origins['bundled'],
				'extension_occurrence_count' => $occurrences['extension'],
				'bundled_occurrence_count'   => $occurrences['bundled'],
			],
			'usages'          => $usages,
			'runtime'         => [
				'runtime_php_version' => PHP_VERSION,
				'parser'             => [
					'name'           => 'nikic/php-parser',
					'version'        => $this->parser_library_version(),
					'target_version' => $metadata['php_version'],
				],
			],
		];

		if ( $state === 'unavailable' ) {
			$result['reason']      = 'No discovered PHP source files could be parsed.';
			$result['reason_code'] = 'all_php_files_unparseable';
		}

		return $result;
	}

	private function parser_version( string $php_version ): PhpVersion {
		$php_version = trim( $php_version );
		return $php_version === '' ? PhpVersion::getNewestSupported() : PhpVersion::fromString( $php_version );
	}

	private function parser_library_version(): string {
		if ( class_exists( '\Composer\InstalledVersions' ) ) {
			$version = \Composer\InstalledVersions::getPrettyVersion( 'nikic/php-parser' );
			if ( is_string( $version ) ) {
				return ltrim( $version, 'v' );
			}
		}

		return '';
	}

	private function display_version( PhpVersion $version ): string {
		return (int) floor( $version->id / 10000 ) . '.' . (int) floor( ( $version->id % 10000 ) / 100 );
	}

	/**
	 * @return array{
	 *     files:string[],
	 *     excluded_directory_count:int,
	 *     excluded_directories:string[],
	 *     excluded_directories_truncated:bool
	 * }
	 */
	private function source_files( string $root ): array {
		$directory            = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
		$excluded_directories = [];
		$filter               = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $current ) use ( $root, &$excluded_directories ): bool {
				if ( $current->isDir() ) {
					$excluded = in_array( strtolower( $current->getBasename() ), self::EXCLUDED_DIRECTORIES, true );
					if ( $excluded ) {
						$relative_path = str_replace(
							'\\',
							'/',
							ltrim( substr( $current->getPathname(), strlen( $root ) ), DIRECTORY_SEPARATOR )
						);
						$excluded_directories[ $relative_path ] = true;
					}

					return ! $excluded;
				}

				return in_array( strtolower( $current->getExtension() ), self::PHP_EXTENSIONS, true );
			}
		);

		$files = [];
		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			if ( $file instanceof \SplFileInfo && $file->isFile() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files, SORT_STRING );
		$excluded_directories = array_keys( $excluded_directories );
		sort( $excluded_directories, SORT_STRING );
		$excluded_directory_count = count( $excluded_directories );

		return [
			'files'                          => $files,
			'excluded_directory_count'       => $excluded_directory_count,
			'excluded_directories'           => array_slice( $excluded_directories, 0, self::MAX_RECORDED_EXCLUDED_DIRECTORIES ),
			'excluded_directories_truncated' => $excluded_directory_count > self::MAX_RECORDED_EXCLUDED_DIRECTORIES,
		];
	}

	private function relative_path( string $root, string $file ): string {
		return str_replace( '\\', '/', ltrim( substr( $file, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
	}

	private function origin( string $file ): string {
		return preg_match( '#^(?:vendor|vendor-prefixed|vendor-scoped|lib-3rd-party)/#i', $file ) === 1
			? 'bundled'
			: 'extension';
	}

	/**
	 * @param array<int,array<string,mixed>> $usages
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_usages( array $usages ): array {
		$aggregated = [];
		foreach ( $usages as $usage ) {
			// Keep one deterministic witness per matchable index key. Origin remains part of
			// the key so extension-owned and bundled dependency evidence stay distinguishable.
			$key = implode( "\0", [
				$usage['surface_key'],
				$usage['usage_kind'],
				$usage['member'] ?? '',
				$usage['origin'],
			] );
			if ( ! isset( $aggregated[ $key ] ) ) {
				$usage['count']     = 1;
				$aggregated[ $key ] = $usage;
				continue;
			}

			$aggregated[ $key ]['count'] ++;
		}

		$usages = array_values( $aggregated );
		usort( $usages, static function ( array $left, array $right ): int {
			return strcmp(
				$left['surface_key'] . ':' . $left['usage_kind'] . ':' . ( $left['member'] ?? '' ) . ':' . $left['origin'],
				$right['surface_key'] . ':' . $right['usage_kind'] . ':' . ( $right['member'] ?? '' ) . ':' . $right['origin']
			);
		} );

		return $usages;
	}

	/**
	 * @param array<string,mixed> $consumer
	 * @param array<string,mixed> $artifact_ref
	 * @return array<string,mixed>
	 */
	private function metadata( string $php_version, array $consumer, array $artifact_ref, string $artifact_path ): array {
		$sha256 = '';
		if ( $artifact_path !== '' && is_file( $artifact_path ) && is_readable( $artifact_path ) ) {
			$hash = hash_file( 'sha256', $artifact_path );
			if ( is_string( $hash ) ) {
				$sha256 = $hash;
			}
		}

		return [
			'php_version' => trim( $php_version ),
			'consumer'    => [
				'slug'       => (string) ( $consumer['slug'] ?? '' ),
				'woo_id'     => (int) ( $consumer['woo_id'] ?? 0 ),
				'version'    => (string) ( $consumer['version'] ?? '' ),
			],
			'artifact'    => [
				'source'   => (string) ( $artifact_ref['source'] ?? '' ),
				'ref'      => $artifact_ref,
				'sha256'   => $sha256,
			],
		];
	}

	/**
	 * @param array<string,mixed> $metadata
	 * @param array<string,mixed> $coverage
	 * @return array<string,mixed>
	 */
	private function unavailable( string $reason, string $reason_code, array $metadata, array $coverage = [] ): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'tool'           => [
				'name'    => 'qit-ecosystem-usage',
				'version' => self::TOOL_VERSION,
			],
			'metadata'       => $metadata,
			'state'          => 'unavailable',
			'coverage'       => array_merge(
				[
					'files_discovered'               => 0,
					'files_scanned'                  => 0,
					'excluded_directory_count'       => 0,
					'excluded_directories'           => [],
					'excluded_directories_truncated' => false,
					'parse_failure_count'             => 0,
					'parse_failures'                 => [],
					'complete'                       => false,
				],
				$coverage
			),
			'summary'        => [
				'usage_count'                => 0,
				'occurrence_count'           => 0,
				'unique_surface_count'       => 0,
				'extension_usage_count'      => 0,
				'bundled_usage_count'        => 0,
				'extension_occurrence_count' => 0,
				'bundled_occurrence_count'   => 0,
			],
			'usages'          => [],
			'reason'          => $reason,
			'reason_code'     => $reason_code,
		];
	}
}
