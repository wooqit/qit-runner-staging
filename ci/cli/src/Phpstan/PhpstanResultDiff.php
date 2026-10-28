<?php

namespace CI_CLI\Phpstan;

class PhpstanResultDiff {
	private const LEVEL_ZERO_IGNORED_IDENTIFIERS = [
		'return.missing',
	];

	private PhpstanReport $baseline;
	private PhpstanReport $target;
	private int $phpstan_level;

	public function __construct( PhpstanReport $baseline, PhpstanReport $target, int $phpstan_level = 0 ) {
		$this->baseline      = $baseline;
		$this->target        = $target;
		$this->phpstan_level = $phpstan_level;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function introduced_errors(): array {
		$baseline = $this->keyed_comparable_errors( $this->baseline );
		$target   = $this->keyed_comparable_errors( $this->target );

		return array_values( array_diff_key( $target, $baseline ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function resolved_errors(): array {
		$baseline = $this->keyed_comparable_errors( $this->baseline );
		$target   = $this->keyed_comparable_errors( $this->target );

		return array_values( array_diff_key( $baseline, $target ) );
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function to_array( array $metadata = [] ): array {
		$introduced = $this->introduced_errors();
		$resolved   = $this->resolved_errors();
		$baseline   = $this->comparable_errors( $this->baseline );
		$target     = $this->comparable_errors( $this->target );

		return [
			'tool'       => [
				'name'    => 'phpstan-regression-diff',
				'version' => '1',
			],
			'state'      => 'observed',
			'metadata'   => $metadata,
			'summary'    => [
				'baseline_count'   => count( $baseline ),
				'target_count'     => count( $target ),
				'introduced_count' => count( $introduced ),
				'resolved_count'   => count( $resolved ),
			],
			'introduced' => $introduced,
			'resolved'   => $resolved,
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function comparable_errors( PhpstanReport $report ): array {
		$errors = $report->get_errors();
		if ( $this->phpstan_level !== 0 ) {
			return $errors;
		}

		// PHPStan refuses to suppress return.missing in configuration. Filter it here
		// so level-zero sweeps retain the same noise policy as the other L0 ignores.
		return array_values( array_filter(
			$errors,
			static function ( array $error ): bool {
				return ! in_array(
					(string) ( $error['identifier'] ?? '' ),
					self::LEVEL_ZERO_IGNORED_IDENTIFIERS,
					true
				);
			}
		) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function keyed_comparable_errors( PhpstanReport $report ): array {
		$keyed = [];
		foreach ( $this->comparable_errors( $report ) as $error ) {
			$keyed[ PhpstanReport::error_key( $error ) ] = $error;
		}

		return $keyed;
	}
}
