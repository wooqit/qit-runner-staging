<?php

namespace CI_CLI\InternalUsage;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

class InternalUsageScanner {
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

	/** @return array<string,mixed> */
	public function scan( string $source_directory, string $php_version = '' ): array {
		$root = realpath( $source_directory );
		if ( $root === false || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return $this->unavailable( sprintf( 'Internal usage source directory is not readable: %s', $source_directory ), 'sut_unavailable' );
		}

		try {
			$files = $this->source_files( $root );
		} catch ( \Throwable $e ) {
			return $this->unavailable( sprintf( 'Unable to enumerate PHP source files in %s: %s', $source_directory, $e->getMessage() ), 'scan_error' );
		}
		if ( empty( $files ) ) {
			return $this->unavailable( sprintf( 'No readable PHP source files were found in %s.', $source_directory ), 'no_php_files' );
		}

		try {
			$parser_version = $this->parser_version( $php_version );
			$parser         = ( new ParserFactory() )->createForVersion( $parser_version );
		} catch ( \Throwable $e ) {
			return $this->unavailable( sprintf( 'Invalid requested PHP parser version: %s', $php_version ), 'invalid_php_version' );
		}
		$findings       = [];
		$parse_failures = [];

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

				$visitor   = new InternalUsageVisitor( $relative_file );
				$traverser = new NodeTraverser();
				$traverser->addVisitor( new NameResolver() );
				$traverser->addVisitor( $visitor );
				$traverser->traverse( $ast );
				$findings = array_merge( $findings, $visitor->get_findings() );
			} catch ( \Throwable $e ) {
				$parse_failures[] = [
					'file'    => $relative_file,
					'message' => $e->getMessage(),
				];
			}
		}

		$findings = $this->normalize_findings( $findings );
		$symbols  = [];
		foreach ( $findings as $finding ) {
			$symbols[ strtolower( $finding['symbol'] ) ] = true;
		}

		usort( $parse_failures, static function ( array $left, array $right ): int {
			return strcmp( $left['file'], $right['file'] );
		} );

		return [
			'tool'     => [
				'name'    => 'qit-internal-usage',
				'version' => '1',
			],
			'metadata' => [
				'php_version' => $this->display_version( $parser_version ),
			],
			'state'    => 'observed',
			'coverage' => [
				'files_scanned'       => count( $files ),
				'parse_failure_count' => count( $parse_failures ),
				'parse_failures'      => $parse_failures,
				'complete'            => empty( $parse_failures ),
			],
			'summary'  => [
				'occurrence_count'    => count( $findings ),
				'unique_symbol_count' => count( $symbols ),
			],
			'findings' => $findings,
		];
	}

	private function parser_version( string $php_version ): PhpVersion {
		$php_version = trim( $php_version );
		return $php_version === '' ? PhpVersion::getNewestSupported() : PhpVersion::fromString( $php_version );
	}

	private function display_version( PhpVersion $version ): string {
		return (int) floor( $version->id / 10000 ) . '.' . (int) floor( ( $version->id % 10000 ) / 100 );
	}

	/** @return string[] */
	private function source_files( string $root ): array {
		$directory = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
		$filter    = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $current ): bool {
				if ( $current->isDir() ) {
					return ! in_array( strtolower( $current->getBasename() ), self::EXCLUDED_DIRECTORIES, true );
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
		return $files;
	}

	private function relative_path( string $root, string $file ): string {
		return str_replace( '\\', '/', ltrim( substr( $file, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
	}

	/**
	 * @param array<int,array{symbol:string,kind:string,file:string,line:int}> $findings
	 * @return array<int,array{symbol:string,kind:string,file:string,line:int}>
	 */
	private function normalize_findings( array $findings ): array {
		$deduplicated = [];
		foreach ( $findings as $finding ) {
			$key                    = strtolower( $finding['symbol'] ) . "\0" . $finding['kind'] . "\0" . $finding['file'] . "\0" . $finding['line'];
			$deduplicated[ $key ] = $finding;
		}

		$findings = array_values( $deduplicated );
		usort( $findings, static function ( array $left, array $right ): int {
			return strcmp(
				$left['file'] . ':' . str_pad( (string) $left['line'], 10, '0', STR_PAD_LEFT ) . ':' . $left['kind'] . ':' . strtolower( $left['symbol'] ),
				$right['file'] . ':' . str_pad( (string) $right['line'], 10, '0', STR_PAD_LEFT ) . ':' . $right['kind'] . ':' . strtolower( $right['symbol'] )
			);
		} );

		return $findings;
	}

	/** @return array<string,mixed> */
	private function unavailable( string $reason, string $reason_code ): array {
		return [
			'tool'     => [
				'name'    => 'qit-internal-usage',
				'version' => '1',
			],
			'state'    => 'unavailable',
			'coverage' => [
				'files_scanned'       => 0,
				'parse_failure_count' => 0,
				'parse_failures'      => [],
				'complete'            => false,
			],
			'summary'  => [
				'occurrence_count'    => 0,
				'unique_symbol_count' => 0,
			],
			'findings' => [],
			'reason'   => $reason,
			'reason_code' => $reason_code,
		];
	}
}
