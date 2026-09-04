<?php

namespace CI_CLI\Phpstan;

class PhpstanReport {
	/** @var array<int, array<string, mixed>> */
	private array $errors;
	/** @var array{bytes:int,sha256:string,first_error:string} */
	private array $diagnostics;

	/**
	 * @param array<int, array<string, mixed>> $errors
	 */
	public function __construct( array $errors, array $diagnostics = [] ) {
		$this->errors      = $errors;
		$this->diagnostics = [
			'bytes'       => (int) ( $diagnostics['bytes'] ?? 0 ),
			'sha256'      => (string) ( $diagnostics['sha256'] ?? '' ),
			'first_error' => (string) ( $diagnostics['first_error'] ?? '' ),
		];
	}

	/**
	 * @param array<string> $strip_prefixes
	 */
	public static function from_file( string $path, array $strip_prefixes = [] ): self {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'PHPStan report is not readable: %s', $path ) );
		}

		$json = file_get_contents( $path );
		if ( $json === false ) {
			throw new \RuntimeException( sprintf( 'Unable to read PHPStan report: %s', $path ) );
		}

		$diagnostics = self::diagnostics_for_contents( $json );
		$data        = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report JSON: %s', $path ) );
		}

		self::validate_schema( $data, $path );
		$diagnostics['first_error'] = self::first_top_level_error( $data );

		if ( $diagnostics['first_error'] !== '' ) {
			throw new \RuntimeException( sprintf(
				'PHPStan report contains top-level errors: %s (%s)',
				$path,
				$diagnostics['first_error']
			) );
		}

		return new self( self::parse_errors( $data, $strip_prefixes ), $diagnostics );
	}

	/** @return array{bytes:int,sha256:string,first_error:string} */
	public static function diagnostics_for_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return [
				'bytes'       => 0,
				'sha256'      => '',
				'first_error' => '',
			];
		}

		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			return [
				'bytes'       => 0,
				'sha256'      => '',
				'first_error' => '',
			];
		}

		$diagnostics = self::diagnostics_for_contents( $contents );
		$data        = json_decode( $contents, true );
		if ( is_array( $data ) ) {
			$diagnostics['first_error'] = self::first_top_level_error( $data );
		}

		return $diagnostics;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/** @return array{bytes:int,sha256:string,first_error:string} */
	public function get_diagnostics(): array {
		return $this->diagnostics;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function keyed_errors(): array {
		$keyed = [];

		foreach ( $this->errors as $error ) {
			$keyed[ self::error_key( $error ) ] = $error;
		}

		return $keyed;
	}

	/**
	 * @param array<string, mixed> $error
	 */
	public static function error_key( array $error ): string {
		return md5( implode( "\0", [
			(string) ( $error['file'] ?? '' ),
			(string) ( $error['line'] ?? '' ),
			(string) ( $error['identifier'] ?? '' ),
			(string) ( $error['message'] ?? '' ),
		] ) );
	}

	/**
	 * @param array<mixed>  $data
	 * @param array<string> $strip_prefixes
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_errors( array $data, array $strip_prefixes ): array {
		$errors = [];

		// Only file-scoped diagnostics are comparable compatibility signals. PHPStan's
		// top-level "errors" are tooling/runtime failures with no stable file payload.
		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			foreach ( $data['files'] as $file => $file_report ) {
				if ( ! is_array( $file_report ) || ! isset( $file_report['messages'] ) || ! is_array( $file_report['messages'] ) ) {
					continue;
				}

				foreach ( $file_report['messages'] as $message ) {
					if ( ! is_array( $message ) ) {
						continue;
					}

					$error = [
						'file'       => self::normalize_file( (string) $file, $strip_prefixes ),
						'line'       => isset( $message['line'] ) ? (int) $message['line'] : null,
						'identifier' => (string) ( $message['identifier'] ?? '' ),
						'message'    => (string) ( $message['message'] ?? '' ),
					];

					$error['symbols'] = self::extract_symbols( $error['message'] );
					$errors[]         = $error;
				}
			}
		}

		usort( $errors, static function ( array $a, array $b ): int {
			return strcmp(
				(string) ( $a['file'] ?? '' ) . ':' . (string) ( $a['line'] ?? '' ) . ':' . (string) ( $a['identifier'] ?? '' ) . ':' . (string) ( $a['message'] ?? '' ),
				(string) ( $b['file'] ?? '' ) . ':' . (string) ( $b['line'] ?? '' ) . ':' . (string) ( $b['identifier'] ?? '' ) . ':' . (string) ( $b['message'] ?? '' )
			);
		} );

		return $errors;
	}

	/** @param array<mixed> $data */
	private static function validate_schema( array $data, string $path ): void {
		$basename = basename( $path );
		if ( ! isset( $data['totals'] ) || ! is_array( $data['totals'] ) ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: missing totals in %s', $basename ) );
		}
		if ( ! array_key_exists( 'files', $data ) || ! is_array( $data['files'] ) ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: missing files in %s', $basename ) );
		}
		if ( ! array_key_exists( 'errors', $data ) || ! is_array( $data['errors'] ) ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: missing errors in %s', $basename ) );
		}

		foreach ( [ 'errors', 'file_errors' ] as $total_name ) {
			if ( ! isset( $data['totals'][ $total_name ] ) || ! is_int( $data['totals'][ $total_name ] ) || $data['totals'][ $total_name ] < 0 ) {
				throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: invalid totals.%s in %s', $total_name, $basename ) );
			}
		}

		if ( $data['totals']['errors'] !== count( $data['errors'] ) ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: incoherent top-level error count in %s', $basename ) );
		}

		$file_error_count = 0;
		foreach ( $data['files'] as $file_report ) {
			if ( ! is_array( $file_report ) || ! isset( $file_report['errors'], $file_report['messages'] ) || ! is_int( $file_report['errors'] ) || $file_report['errors'] < 0 || ! is_array( $file_report['messages'] ) ) {
				throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: invalid file report in %s', $basename ) );
			}
			if ( $file_report['errors'] !== count( $file_report['messages'] ) ) {
				throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: incoherent file message count in %s', $basename ) );
			}
			foreach ( $file_report['messages'] as $message ) {
				if ( ! is_array( $message ) || ! isset( $message['message'] ) || ! is_string( $message['message'] ) || trim( $message['message'] ) === '' ) {
					throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: invalid file message in %s', $basename ) );
				}
			}
			$file_error_count += count( $file_report['messages'] );
		}

		if ( $data['totals']['file_errors'] !== $file_error_count ) {
			throw new \RuntimeException( sprintf( 'Invalid PHPStan report schema: incoherent totals.file_errors in %s', $basename ) );
		}
	}

	/** @return array{bytes:int,sha256:string,first_error:string} */
	private static function diagnostics_for_contents( string $contents ): array {
		return [
			'bytes'       => strlen( $contents ),
			'sha256'      => hash( 'sha256', $contents ),
			'first_error' => '',
		];
	}

	/** @param array<mixed> $data */
	private static function first_top_level_error( array $data ): string {
		$errors = $data['errors'] ?? [];
		if ( ! is_array( $errors ) || empty( $errors ) ) {
			return '';
		}

		$first = $errors[0];
		if ( is_array( $first ) ) {
			$first = $first['message'] ?? json_encode( $first );
		}

		$message = is_scalar( $first ) ? (string) $first : 'PHPStan reported a top-level error.';
		$message = preg_replace( '#https?://[^\s]+#i', '[redacted-url]', $message ) ?? '';
		$message = preg_replace( '/\b(authorization|token|secret|password)\s*[:=]\s*\S+/i', '$1=[redacted]', $message ) ?? '';
		$message = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $message ) ?? '' );

		return substr( $message, 0, 500 );
	}

	/**
	 * @param array<string> $strip_prefixes
	 */
	private static function normalize_file( string $file, array $strip_prefixes ): string {
		$file = str_replace( '\\', '/', $file );

		foreach ( $strip_prefixes as $prefix ) {
			$prefix = rtrim( str_replace( '\\', '/', $prefix ), '/' ) . '/';
			if ( substr( $file, 0, strlen( $prefix ) ) === $prefix ) {
				return substr( $file, strlen( $prefix ) );
			}
		}

		return $file;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function extract_symbols( string $message ): array {
		$symbols = [];
		$patterns = [
			'/Call to an undefined method ([A-Za-z0-9_\\\\]+)::([A-Za-z0-9_]+)\\(\\)/' => [ 'class', 'method' ],
			'/Call to an undefined function ([A-Za-z0-9_\\\\]+)\\(\\)/' => [ 'function' ],
			'/Access to an undefined property ([A-Za-z0-9_\\\\]+)::\\$([A-Za-z0-9_]+)/' => [ 'class', 'property' ],
			'/abstract method ([A-Za-z0-9_\\\\]+) from interface ([A-Za-z0-9_\\\\]+)/i' => [ 'method', 'interface' ],
			'/Class ([A-Za-z0-9_\\\\]+) not found/' => [ 'class' ],
			'/Interface ([A-Za-z0-9_\\\\]+) not found/' => [ 'interface' ],
		];

		foreach ( $patterns as $pattern => $fields ) {
			if ( preg_match( $pattern, $message, $matches ) !== 1 ) {
				continue;
			}

			$symbol = [];
			foreach ( $fields as $index => $field ) {
				$symbol[ $field ] = $matches[ $index + 1 ] ?? '';
			}

			$symbols[] = $symbol;
		}

		return $symbols;
	}
}
