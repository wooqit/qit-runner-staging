<?php

namespace CI_CLI\Results;

use CI_CLI\RequestBuilder;

class PlaywrightResults extends Results {
	public function convert_pw_to_puppeteer( array $results ): array {
		if ( array_key_exists( 'config', $results ) && array_key_exists( '_testGroupsCount', $results['config'] ) ) {
			$numTotalSuites = $results['config']['_testGroupsCount'];
		} else if ( array_key_exists( 'suites', $results ) ) {
			$numTotalSuites = count( $results['suites'] );
		}

		$formatted_result = [
			'numFailedTestSuites'  => 0,
			'numPassedTestSuites'  => 0,
			'numPendingTestSuites' => 0,
			'numTotalTestSuites'   => $numTotalSuites ?? 0,
			'numFailedTests'       => 0,
			'numPassedTests'       => 0,
			'numPendingTests'      => 0,
			'numTotalTests'        => 0,
			'testResults'          => [],
			'summary'              => '',
		];

		if ( ! empty( $results['suites'] ) ) {
			foreach ( $results['suites'] as $suite ) {
				$result = [
					'file'        => $suite['file'],
					'status'      => 'passed',
					'has_pending' => false,
					'tests'       => []
				];

				if ( ! empty( $suite['suites'] ) ) {
					foreach ( $suite['suites'] as $test ) {
						$key                     = $test['title'];
						$result['tests'][ $key ] = [];

						foreach ( $test['specs'] as $spec ) {
							$this->parse_specs( $spec, $result, $formatted_result, $key );
						}

						$this->parse_possible_suite( $test, $result, $formatted_result, $key );
					}
				}

				if ( ! empty( $suite['specs'] ) ) {
					foreach ( $suite['specs'] as $spec ) {
						$key                     = $spec['title'];
						$result['tests'][ $key ] = [];
						$this->parse_specs( $spec, $result, $formatted_result, $key );
					}
				}

				if ( $result['status'] === 'failed' ) {
					$formatted_result['numFailedTestSuites'] = $formatted_result['numFailedTestSuites'] + 1;
				}

				if ( $result['status'] === 'passed' && ! $result['has_pending'] ) {
					$formatted_result['numPassedTestSuites'] = $formatted_result['numPassedTestSuites'] + 1;
				}

				if ( $result['has_pending'] ) {
					$formatted_result['numPendingTestSuites'] = $formatted_result['numPendingTestSuites'] + 1;
				}

				if ( $result['status'] === 'flaky' ) {
					$formatted_result['numPassedTestSuites'] = $formatted_result['numPassedTestSuites'] + 1;
				}

				if (
					array_key_exists( 'is_pending', $result ) &&
					$result['has_pending']
				) {
					$formatted_result['numPendingTestSuites'] = $formatted_result['numPendingTestSuites'] + 1;
				}

				$formatted_result['testResults'][] = $result;
			}
		}

		if ( $formatted_result['numPendingTests'] > 0 ) {
			$formatted_result['summary'] = sprintf(
				'%d total, %d passed, %d failed, %d skipped.',
				$formatted_result['numTotalTests'],
				$formatted_result['numPassedTests'],
				$formatted_result['numFailedTests'],
				$formatted_result['numPendingTests'],
			);
		} else {
			$formatted_result['summary'] = sprintf(
				'%d total, %d passed, %d failed.',
				$formatted_result['numTotalTests'],
				$formatted_result['numPassedTests'],
				$formatted_result['numFailedTests'],
			);
		}

		return $formatted_result;
	}

	private function get_status( array $spec ) {
		if ( array_key_exists( 'status', $spec['tests'][0] ) ) {
			$status = $spec['tests'][0]['status'];
		} else {
			$status = end( $spec['tests'][0]['results'] );
		}

		if ( $status === 'skipped' ) {
			$status = 'pending';
		}

		if ( $status === 'expected' ) {
			$status = 'passed';
		}

		if ( $status === 'unexpected' ) {
			$status = 'failed';
		}

		if ( $status === 'flaky' ) {
			$status = 'passed';
		}

		return $status;
	}

	private function parse_specs( array $spec, array &$result, array &$formatted_result, string $key ) {
		$title  = $spec['title'];
		$status = $this->get_status( $spec );

		$result['tests'][ $key ][] = [
			'title'  => $title,
			'status' => $status
		];

		switch ( $status ) {
			case 'failed':
				$result['status'] = 'failed';
				// increment failed test count
				$formatted_result['numFailedTests'] = $formatted_result['numFailedTests'] + 1;
				break;
			case 'passed':
				// increment passed test count
				$formatted_result['numPassedTests'] = $formatted_result['numPassedTests'] + 1;
				break;
			case 'skipped':
			case 'pending':
				$result['has_pending'] = true;
				// increment skipped/pending test count
				$formatted_result['numPendingTests'] = $formatted_result['numPendingTests'] + 1;
				break;
			default:
				$result['status'] = 'failed';
				// increment failed test count
				$formatted_result['numFailedTests'] = $formatted_result['numFailedTests'] + 1;
				break;
		}

		$formatted_result['numTotalTests'] = $formatted_result['numTotalTests'] + 1;
	}

	private function parse_possible_suite( array $suite, array &$result, array &$formatted_result, string $parent_key ) {
		if ( array_key_exists( 'suites', $suite ) ) {
			foreach ( $suite['suites'] as $suite ) {
				$suite_key                     = $parent_key . ' > ' . $suite['title'];
				$result['tests'][ $suite_key ] = [];

				foreach ( $suite['specs'] as $spec ) {
					$this->parse_specs( $spec, $result, $formatted_result, $suite_key );
				}

				$this->parse_possible_suite( $suite, $result, $formatted_result, $suite_key );
			}
		}
	}

	protected function get_test_result_json(): string {
		$json_file = "{$this->workspace}/{$this->partial_path}";

		if ( file_exists( $json_file ) ) {
			$results = json_decode( file_get_contents( $json_file ), true );

			return json_encode( $this->convert_pw_to_puppeteer( $results ) );
		} else {
			$this->output->writeln( "Test result file not found: $json_file" );
			$test_result_json            = $this->convert_pw_to_puppeteer( [] );
			$test_result_json['summary'] = 'Test failed before it was executed.';

			return json_encode( $test_result_json );
		}
	}

	public function get_test_result_json_original_compressed(): string {
		$json_file = "{$this->workspace}/{$this->partial_path}";

		if ( file_exists( $json_file ) ) {
			/*
			 * Since this data is for history purposes only, we compress it.
			 *
			 * gzcompress will reduce the JSON by 10x~
			 *
			 * base64_encode will convert the binary compressed string into an alphanumeric string,
			 * so it's easier to send, store, retrieve, etc, without worrying about encoding issues.
			 */
			return base64_encode( gzcompress( file_get_contents( $json_file ) ) );
		} else {
			$this->output->writeln( "Test result file not found: $json_file" );

			return '';
		}
	}

	private function get_debug_log(): string {
		$file = "{$this->workspace}/debug_prepared.log";

		if ( file_exists( $file ) ) {
			$log = file_get_contents( $file );

			if ( empty( $log ) ) {
				$this->output->writeln( "$file is empty." );
			} else {
				// Limit debug_log to a max of 8mb.
				if ( strlen( $log ) > 8 * 1024 * 1024 ) {
					$log = substr( $log, 0, 8 * 1024 * 1024 );
				}
			}

			return $log;
		}

		$this->output->writeln( "$file does not exist." );

		return '';
	}

	public function send_results(): string {
		$ctrf_file = "{$this->workspace}/" . getenv( 'CTRF_PARTIAL_PATH' );
		$ctrf_json = file_exists( $ctrf_file ) ? file_get_contents( $ctrf_file ) : '';

		return ( new RequestBuilder( $this->get_url(), $this->output ) )
			->with_method( 'POST' )
			->with_post_body( [
				'test_run_id'               => $this->test_run_id,
				'sut_version'               => $this->sut_version,
				'status'                    => $this->get_test_status(),
				'test_result_json'          => $this->get_test_result_json(),
				'test_result_json_original' => $this->get_test_result_json_original_compressed(),
				'ctrf_json'                 => $ctrf_json,
				'debug_log'                 => $this->get_debug_log(),
				'aws_allure'                => $this->get_aws_presign_data(),
				'ci_secret'                 => $this->ci_secret
			] )
			->with_expected_status_codes( [ 200 ] )
			->with_timeout_in_seconds( 15 )
			->with_headers( [
				'Content-Type: application/json',
				'Accept: application/json'
			] )
			->request();
	}
}