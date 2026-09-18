<?php

namespace CI_CLI;

require_once __DIR__ . '/WorkflowFailure.php';

class CompatibilityRegressionUnavailableResult {
	/**
	 * @param array<string,mixed> $metadata
	 */
	public static function write_if_missing( string $result_path, string $failure_path, array $metadata ): bool {
		if ( is_file( $result_path ) ) {
			return false;
		}

		$failure = WorkflowFailure::read( $failure_path );
		$reason  = $failure['message'] ?? 'Compatibility regression workflow did not produce a diff result.';
		$result  = [
			'tool'       => [
				'name'    => 'phpstan-regression-diff',
				'version' => '1',
			],
			'state'      => 'unavailable',
			'reason'     => $reason,
			'metadata'   => [
				'baseline_woocommerce_version' => (string) ( $metadata['baseline_woocommerce_version'] ?? '' ),
				'target_woocommerce_version'   => (string) ( $metadata['target_woocommerce_version'] ?? '' ),
				'sut_version'                  => (string) ( $metadata['sut_version'] ?? '' ),
			],
			'summary'    => [
				'baseline_count'   => 0,
				'target_count'     => 0,
				'introduced_count' => 0,
				'resolved_count'   => 0,
			],
			'introduced' => [],
			'resolved'   => [],
		];
		if ( isset( $metadata['runtime'] ) && is_array( $metadata['runtime'] ) ) {
			$result['metadata']['runtime'] = [
				'requested_php_version' => self::sanitize_text( (string) ( $metadata['runtime']['requested_php_version'] ?? '' ) ),
				'actual_php_version'    => self::sanitize_text( (string) ( $metadata['runtime']['actual_php_version'] ?? '' ) ),
				'php_extensions'        => array_values( array_filter( $metadata['runtime']['php_extensions'] ?? [], 'is_string' ) ),
				'phpstan_version'       => self::sanitize_text( (string) ( $metadata['runtime']['phpstan_version'] ?? '' ) ),
			];
		}

		if ( $failure !== null ) {
			$result['failure'] = $failure;
		}

		$directory = dirname( $result_path );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( sprintf( 'Unable to create compatibility result directory: %s', $directory ) );
		}

		$json = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || file_put_contents( $result_path, $json . PHP_EOL ) === false ) {
			throw new \RuntimeException( sprintf( 'Unable to write compatibility result: %s', $result_path ) );
		}

		return true;
	}

	private static function sanitize_text( string $value ): string {
		return substr( trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) ?? '' ), 0, 100 );
	}

}
