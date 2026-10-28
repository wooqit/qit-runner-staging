<?php

namespace CI_CLI\Results;

use CI_CLI\RequestBuilder;
use CI_CLI\WorkflowFailure;

class GenericResults extends Results {

	protected function get_test_result_json(): string {
		// Guard against empty test_result_json.
		if ( empty( trim( $this->test_result_json ) ) ) {
			$this->output->writeln( 'Warning: test_result_json is empty. Sending a structured workflow failure.' );
			return $this->get_missing_result_json();
		}

		$json_file = "{$this->workspace}/{$this->test_result_json}";

		// Some workflows pass JSON directly rather than a result file path.
		json_decode( $this->test_result_json, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $this->test_result_json;
		}

		// Check if it's a directory (not a file) - defensive guard.
		if ( is_dir( $json_file ) ) {
			$this->output->writeln( "Error: $json_file is a directory, not a file." );
			return $this->get_missing_result_json();
		}

		if ( file_exists( $json_file ) ) {
			$contents = file_get_contents( $json_file );

			if ( $contents !== false ) {
				json_decode( $contents, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					return $contents;
				}

				$this->output->writeln( "Error: $json_file does not contain valid JSON." );
				return $this->get_missing_result_json();
			}

			$this->output->writeln( "Error: $json_file could not be read." );
		} else {
			$this->output->writeln( "$json_file was not found. Sending a structured workflow failure." );
		}

		return $this->get_missing_result_json();
	}

	private function get_missing_result_json(): string {
		// Cancellation is a lifecycle state, not an unavailable workflow result.
		if ( $this->cancelled ) {
			return '[]';
		}

		$failure_path = getenv( 'QIT_FAILURE_OUTPUT' );
		$failure      = WorkflowFailure::read( $failure_path === false ? '' : $failure_path );

		if ( $failure === null ) {
			$failure = [
				'stage'   => 'analysis',
				'code'    => 'result_artifact_missing',
				'message' => 'Test workflow did not produce its result artifact.',
			];
		}

		return (string) json_encode( [ 'failure' => $failure ] );
	}

	/**
	 * Get the debug log content from DEBUG_LOG environment variable.
	 *
	 * Follows the same pattern as PlaywrightResults for consistency.
	 * The DEBUG_LOG env var can be either a file path or raw content.
	 * File paths are scrubbed to be relative to WordPress installation.
	 *
	 * @return string The debug log content with scrubbed paths, or empty string if not available.
	 */
	protected function get_debug_log(): string {
		$debug_log_env = getenv( 'DEBUG_LOG' );
		if ( $debug_log_env === false || empty( trim( $debug_log_env ) ) ) {
			return '';
		}

		$content = '';

		// If it's a file path, read the file.
		if ( file_exists( $debug_log_env ) ) {
			$content = file_get_contents( $debug_log_env );

			if ( $content === false ) {
				$this->output->writeln( "Warning: Could not read debug log file: $debug_log_env" );
				return '';
			}

			// Limit to 8MB like PlaywrightResults.
			if ( strlen( $content ) > 8 * 1024 * 1024 ) {
				$content = substr( $content, 0, 8 * 1024 * 1024 );
				$this->output->writeln( 'Debug log truncated to 8MB.' );
			}
		} else {
			// Otherwise treat as raw content.
			$content = $debug_log_env;
		}

		// Scrub absolute paths to be relative to WordPress installation.
		return $this->scrub_paths( $content );
	}

	/**
	 * Scrub absolute file paths from debug log content.
	 *
	 * Replaces absolute paths with relative paths for cleaner output
	 * and to avoid WAF blocking requests containing server paths.
	 *
	 * @param string $content The debug log content.
	 * @return string Content with scrubbed paths.
	 */
	protected function scrub_paths( string $content ): string {
		// Common CI runner base paths to scrub.
		$paths_to_scrub = [
			// GitHub Actions runner paths.
			'/home/runner/work/[^/]+/[^/]+/env/' => '',
			'/home/runner/work/[^/]+/[^/]+/' => '',
			// Generic patterns for WordPress installations.
			'/var/www/html/' => '',
			'/var/www/' => '',
			// Phar paths (WP-CLI).
			'phar://[^/]+/' => 'wp-cli/',
		];

		foreach ( $paths_to_scrub as $pattern => $replacement ) {
			$content = preg_replace( '#' . $pattern . '#', $replacement, $content );
		}

		return $content;
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

	/**
	 * Compress a string using gzcompress + base64_encode.
	 *
	 * @param string $data The data to compress.
	 * @return string Compressed data, or original data if compression fails.
	 */
	private function compress_field( string $data ): string {
		if ( empty( $data ) ) {
			return '';
		}

		$compressed = gzcompress( $data );
		if ( $compressed !== false ) {
			return base64_encode( $compressed );
		}

		// gzcompress failed, send uncompressed as fallback.
		return $data;
	}

	public function send_results(): string {
		$test_result_json = $this->get_test_result_json();
		$exit_codes_json  = $this->get_exit_codes_json();
		$debug_log        = $this->get_debug_log();

		$post_body = [
			'test_run_id'      => $this->test_run_id,
			'sut_version'      => $this->sut_version,
			'status'           => $this->get_test_status(),
			'test_result_json' => $this->compress_field( $test_result_json ),
			'ci_secret'        => $this->ci_secret,
		];

		// Only add exit_codes_json if we have something to send.
		if ( ! empty( $exit_codes_json ) ) {
			$post_body['exit_codes_json'] = $exit_codes_json;
		}

		// Include debug_log if available (for fatal error detection).
		if ( ! empty( $debug_log ) ) {
			$post_body['debug_log'] = $this->compress_field( $debug_log );
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
