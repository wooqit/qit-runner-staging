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

	public function test_names_the_interface_method_phpstan_reports_with_parentheses(): void {
		$message = 'Non-abstract class WC_Stripe_Agentic_Commerce_Csv_Feed contains abstract method get_entry_count() from interface Automattic\\WooCommerce\\Internal\\ProductFeed\\Feed\\FeedInterface.';
		$report  = tempnam( sys_get_temp_dir(), 'phpstan' );
		file_put_contents( $report, json_encode( [
			'totals' => [ 'errors' => 0, 'file_errors' => 1 ],
			'files'  => [
				'/tmp/qit/wp-content/plugins/woocommerce-gateway-stripe/includes/class-feed.php' => [
					'errors'   => 1,
					'messages' => [ [ 'message' => $message, 'line' => 7, 'ignorable' => true, 'identifier' => 'method.abstract' ] ],
				],
			],
			'errors' => [],
		] ) );

		try {
			$errors = PhpstanReport::from_file( $report, [ '/tmp/qit/' ] )->get_errors();
		} finally {
			unlink( $report );
		}

		$this->assertSame(
			[ [ 'method' => 'get_entry_count', 'interface' => 'Automattic\\WooCommerce\\Internal\\ProductFeed\\Feed\\FeedInterface' ] ],
			$errors[0]['symbols']
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
