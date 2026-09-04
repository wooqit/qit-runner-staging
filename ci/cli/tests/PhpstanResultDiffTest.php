<?php

use CI_CLI\Phpstan\PhpstanReport;
use CI_CLI\Phpstan\PhpstanResultDiff;

class PhpstanResultDiffTest extends \PHPUnit\Framework\TestCase {
	public function test_detects_target_only_phpstan_errors(): void {
		$baseline = PhpstanReport::from_file( __DIR__ . '/data/phpstan-baseline.json', [ '/tmp/qit/' ] );
		$target   = PhpstanReport::from_file( __DIR__ . '/data/phpstan-target.json', [ '/tmp/qit/' ] );
		$diff     = new PhpstanResultDiff( $baseline, $target );

		$result = $diff->to_array( [
			'baseline_woocommerce_version' => '10.8.1',
			'target_woocommerce_version'   => '10.9.0',
			'sut_version'                  => '9.4.0',
		] );

		$this->assertSame( 'observed', $result['state'] );
		$this->assertSame( 1, $result['summary']['introduced_count'] );
		$this->assertSame( 0, $result['summary']['resolved_count'] );
		$this->assertSame( 'method.abstract', $result['introduced'][0]['identifier'] );
		$this->assertSame(
			'wp-content/plugins/woocommerce-gateway-stripe/includes/class-wc-stripe-payment-gateway.php',
			$result['introduced'][0]['file']
		);
		$this->assertSame( 'get_payment_method_configuration', $result['introduced'][0]['symbols'][0]['method'] );
		$this->assertSame(
			'Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodTypeInterface',
			$result['introduced'][0]['symbols'][0]['interface']
		);
	}

	public function test_rejects_target_top_level_phpstan_errors(): void {
		$baseline = PhpstanReport::from_file( __DIR__ . '/data/phpstan-baseline.json', [ '/tmp/qit/' ] );
		$this->assertCount( 1, $baseline->get_errors() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PHPStan report contains top-level errors' );
		PhpstanReport::from_file( __DIR__ . '/data/phpstan-target-top-level-error.json', [ '/tmp/qit/' ] );
	}

	public function test_level_zero_filters_missing_return_from_both_sides(): void {
		$report   = __DIR__ . '/data/phpstan-return-missing.json';
		$baseline = PhpstanReport::from_file( $report, [ '/tmp/qit/' ] );
		$target   = PhpstanReport::from_file( $report, [ '/tmp/qit/' ] );
		$diff     = new PhpstanResultDiff( $baseline, $target, 0 );
		$result   = $diff->to_array();

		$this->assertSame( 'observed', $result['state'] );
		$this->assertSame( 0, $result['summary']['baseline_count'] );
		$this->assertSame( 0, $result['summary']['target_count'] );
		$this->assertSame( 0, $result['summary']['introduced_count'] );
		$this->assertSame( 0, $result['summary']['resolved_count'] );
	}

	public function test_level_zero_does_not_introduce_target_only_missing_return(): void {
		$baseline = new PhpstanReport( [] );
		$target   = PhpstanReport::from_file( __DIR__ . '/data/phpstan-return-missing.json', [ '/tmp/qit/' ] );
		$diff     = new PhpstanResultDiff( $baseline, $target, 0 );
		$result   = $diff->to_array();

		$this->assertSame( 0, $result['summary']['target_count'] );
		$this->assertSame( 0, $result['summary']['introduced_count'] );
		$this->assertSame( [], $result['introduced'] );
	}

	public function test_higher_levels_report_target_only_missing_return(): void {
		$baseline = new PhpstanReport( [] );
		$target   = PhpstanReport::from_file( __DIR__ . '/data/phpstan-return-missing.json', [ '/tmp/qit/' ] );
		$diff     = new PhpstanResultDiff( $baseline, $target, 1 );
		$result   = $diff->to_array();

		$this->assertSame( 1, $result['summary']['target_count'] );
		$this->assertSame( 1, $result['summary']['introduced_count'] );
		$this->assertSame( 'return.missing', $result['introduced'][0]['identifier'] );
	}
}
