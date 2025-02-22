<?php

namespace CI_CLI\Results;

use CI_CLI\RequestBuilder;

class GenericResults extends Results {

	protected function get_test_result_json(): string {
		$json_file = "{$this->workspace}/{$this->test_result_json}";

		if ( file_exists( $json_file ) ) {
			return file_get_contents( $json_file );
		} else {
			$this->output->writeln( "$json_file was not found. Sending raw test_result_json." );

			return $this->test_result_json;
		}
	}

	/**
	 * Build a separate JSON structure for exit codes.
	 *
	 * @return string A JSON-encoded object of exit codes, or an empty string if none.
	 */
	protected function get_exit_codes_json(): string {
		$exit_codes = [];

		// Check for PHPCS exit code.
		$phpcs_exit_code = getenv( 'PHPCS_EXIT_CODE' );
		if ( $phpcs_exit_code !== false ) {
			$exit_codes['phpcs'] = [
				'exit_code' => (int) $phpcs_exit_code,
				'expected'  => [ 0 ],
			];
		}

		// Check for Semgrep exit code.
		$semgrep_exit_code = getenv( 'SEMGREP_EXIT_CODE' );
		if ( $semgrep_exit_code !== false ) {
			$exit_codes['semgrep'] = [
				'exit_code' => (int) $semgrep_exit_code,
				'expected'  => [ 0 ],
			];
		}

		if ( ! empty( $exit_codes ) ) {
			return json_encode( $exit_codes );
		}

		return '';
	}

	public function send_results(): string {
		$test_result_json = $this->get_test_result_json();
		$exit_codes_json  = $this->get_exit_codes_json();

		$post_body = [
			'test_run_id'      => $this->test_run_id,
			'sut_version'      => $this->sut_version,
			'status'           => $this->get_test_status(),
			'test_result_json' => $test_result_json,
			'ci_secret'        => $this->ci_secret,
		];

		// Only add exit_codes_json if we have something to send.
		if ( ! empty( $exit_codes_json ) ) {
			$post_body['exit_codes_json'] = $exit_codes_json;
		}

		return ( new RequestBuilder( $this->get_url(), $this->output ) )
			->with_method( 'POST' )
			->with_post_body( $post_body )
			->with_expected_status_codes( [ 200 ] )
			->with_timeout_in_seconds( 15 )
			->with_headers( [
				'Content-Type: application/json',
				'Accept: application/json'
			] )
			->request();
	}
}