<?php

use CI_CLI\Results\Results;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

class ResultsStatusTestDouble extends Results {
	public function status(): string {
		return $this->get_test_status();
	}

	protected function get_test_result_json(): string {
		return '';
	}

	public function send_results(): string {
		return '';
	}
}

class ResultsTest extends TestCase {
	/**
	 * @param mixed $cancelled Cancellation value as received from the environment.
	 */
	private function make_results( $cancelled, string $test_result = 'success' ): ResultsStatusTestDouble {
		return new ResultsStatusTestDouble(
			'',
			'',
			'',
			'',
			'',
			'',
			$cancelled,
			$test_result,
			'',
			'',
			new NullOutput()
		);
	}

	public function test_false_string_is_not_treated_as_cancelled(): void {
		$this->assertSame( 'success', $this->make_results( 'false' )->status() );
	}

	public function test_empty_string_is_not_treated_as_cancelled(): void {
		$this->assertSame( 'success', $this->make_results( '' )->status() );
	}

	public function test_true_environment_values_are_treated_as_cancelled(): void {
		$this->assertSame( 'cancelled', $this->make_results( 'true' )->status() );
		$this->assertSame( 'cancelled', $this->make_results( '1' )->status() );
	}

	public function test_non_cancelled_failure_remains_failed(): void {
		$this->assertSame( 'failed', $this->make_results( 'false', 'failed' )->status() );
	}
}
