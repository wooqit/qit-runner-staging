<?php

namespace CI_CLI;

use CI_CLI\Exceptions\NetworkErrorException;
use Symfony\Component\Console\Output\OutputInterface;

class RequestBuilder {
	/** @var OutputInterface $output */
	protected OutputInterface $output;

	/** @var string $url */
	protected $url;

	/** @var string $method */
	protected $method = 'POST';

	/** @var array<scalar> $post_body */
	protected $post_body = [];

	/** @var array<int, mixed> $curl_opts */
	protected $curl_opts = [];


	/** @var array<int> */
	protected $expected_status_codes = [ 200 ];

	/** @var int */
	protected $timeout_in_seconds = 15;

	/** @var array<string> */
	protected $headers = [];

	/** @var int */
	protected $retry_429 = 5;

	public function __construct( string $url = '', OutputInterface $output = null ) {
		$this->url    = $url;
		$this->output = $output;
	}

	/**
	 * @param string $url The URL to send the request to.
	 *
	 * @return $this
	 */
	public function with_url( string $url ): self {
		$this->url = $url;

		return $this;
	}

	/**
	 * @param string $method The HTTP method. Defaults to "GET".
	 *
	 * @return $this
	 */
	public function with_method( string $method ): self {
		$this->method = $method;

		return $this;
	}

	/**
	 * @param array<scalar> $post_body Optionally set curl's post_body.
	 *
	 * @return $this
	 */
	public function with_post_body( array $post_body ): self {
		$this->post_body = $post_body;

		return $this;
	}

	/**
	 * @param array<int, mixed> $curl_opts Optionally set curl's curl_opts.
	 *
	 * @return $this
	 */
	public function with_curl_opts( array $curl_opts ): self {
		$this->curl_opts = $curl_opts;

		return $this;
	}

	/**
	 * @param array<int> $expected_status_codes Optionally set expected response status code.
	 *
	 * @return $this
	 */
	public function with_expected_status_codes( array $expected_status_codes ): self {
		$this->expected_status_codes = $expected_status_codes;

		return $this;
	}

	/**
	 * @param int $timeout_in_seconds
	 *
	 * @return RequestBuilder
	 */
	public function with_timeout_in_seconds( int $timeout_in_seconds ): RequestBuilder {
		$this->timeout_in_seconds = $timeout_in_seconds;

		return $this;
	}

	/**
	 * @param array<string> $headers
	 *
	 * @return RequestBuilder
	 */
	public function with_headers( array $headers ): RequestBuilder {
		$this->headers = $headers;

		return $this;
	}

	public function request(): string {
		retry_request: // phpcs:ignore Generic.PHP.DiscourageGoto.Found
		if ( defined( 'UNIT_TESTS' ) ) {
			$mocked = App::getVar( 'mock_' . $this->url );
			if ( is_null( $mocked ) ) {
				throw new \LogicException( 'No mock found for ' . $this->url );
			}

			App::setVar( 'mocked_request', $this->to_array() );

			return $mocked;
		}

		if ( empty( $this->url ) ) {
			throw new \LogicException( 'URL cannot be empty.' );
		}

		$curl = curl_init();

		$curl_parameters = [
			CURLOPT_URL            => $this->url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => $this->timeout_in_seconds,
			CURLOPT_TIMEOUT        => $this->timeout_in_seconds,
			CURLOPT_HEADER         => true,
		];

		switch ( $this->method ) {
			case 'GET':
				// no-op.
				break;
			case 'POST':
				$json_data                             = json_encode( $this->post_body );
				$curl_parameters[ CURLOPT_POST ]       = true;
				$curl_parameters[ CURLOPT_POSTFIELDS ] = $json_data;
				break;
			default:
				$curl_parameters[ CURLOPT_CUSTOMREQUEST ] = $this->method;
				break;
		}

		if ( ! empty( $this->headers ) ) {
			$curl_parameters[ CURLOPT_HTTPHEADER ] = $this->headers;
		}

		if ( ! empty( $this->curl_opts ) ) {
			$curl_parameters = array_replace( $curl_parameters, $this->curl_opts );
		}

		curl_setopt_array( $curl, $curl_parameters );

		$this->output->writeln( "Running External Request" );
		$result               = curl_exec( $curl );
		$curl_error           = curl_error( $curl );

		// Extract header size and separate headers from body.
		$header_size = curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
		$headers     = substr( $result, 0, $header_size );
		$body        = substr( $result, $header_size );

		$response_status_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		$this->output->writeln( json_encode( $this->post_body ) );
		$this->output->writeln( json_encode( $this->url ) );
		$this->output->writeln( "External Request Complete" );

		if ( ! in_array( $response_status_code, $this->expected_status_codes, true ) ) {
			if ( ! empty( $curl_error ) ) {
				// Network error, such as a timeout, etc.
				$error_message = $curl_error;
			} else {
				// Application error, such as invalid parameters, etc.
				$error_message = $body;
				$json_response = json_decode( $error_message, true );

				if ( is_array( $json_response ) && array_key_exists( 'message', $json_response ) ) {
					$error_message = $json_response['message'];
				}
			}

			if ( $response_status_code === 429 ) {
				if ( $this->retry_429 > 0 ) {
					--$this->retry_429;
					$sleep_seconds = $this->wait_after_429( $headers );
					$this->output->writeln( sprintf( '<comment>Request failed... Waiting %d seconds and retrying (429 Too many Requests)</comment>', $sleep_seconds ) );

					sleep( $sleep_seconds );
					goto retry_request; // phpcs:ignore Generic.PHP.DiscourageGoto.Found
				}
			}

			throw new NetworkErrorException(
				sprintf(
					'Expected return status code(s): %s. Got return status code: %s. Error message: %s',
					implode( ', ', $this->expected_status_codes ),
					$response_status_code,
					$error_message
				),
				$response_status_code
			);
		}

		return $body;
	}

	/**
	 * This code is ported from QIT CLI.
	 *
	 * Consider this read-only.
	 *
	 * @link https://github.com/woocommerce/qit-cli/blob/trunk/src/src/RequestBuilder.php
	 */
	protected function wait_after_429( string $headers, int $max_wait = 60 ): int {
		$retry_after = null;

		// HTTP dates are always expressed in GMT, never in local time. (RFC 9110 5.6.7).
		$gmt_timezone = new \DateTimeZone( 'GMT' );

		// HTTP headers are case-insensitive according to RFC 7230.
		$headers = strtolower( $headers );

		foreach ( explode( "\r\n", $headers ) as $header ) {
			/**
			 * Retry-After header is specified by RFC 9110 10.2.3
			 *
			 * It can be formatted as http-date, or int (seconds).
			 *
			 * Retry-After: Fri, 31 Dec 1999 23:59:59 GMT
			 * Retry-After: 120
			 *
			 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-10.2.3
			 */
			if ( strpos( $header, 'retry-after:' ) !== false ) {
				$retry_after_header = trim( substr( $header, strpos( $header, ':' ) + 1 ) );

				// seconds.
				if ( is_numeric( $retry_after_header ) ) {
					$retry_after = intval( $retry_after_header );
				} else {
					// Parse as HTTP-date in GMT timezone.
					try {
						$retry_after = ( new \DateTime( $retry_after_header, $gmt_timezone ) )->getTimestamp() - ( new \DateTime( 'now', $gmt_timezone ) )->getTimestamp();
					} catch ( \Exception $e ) {
						$retry_after = null;
					}
					// http-date.
					$retry_after_time = strtotime( $retry_after_header );
					if ( $retry_after_time !== false ) {
						$retry_after = $retry_after_time - time();
					}
				}

				if ( ! defined( 'UNIT_TESTS' ) ) {
					$this->output->writeln( sprintf( 'Got 429. Retrying after %d seconds...', $retry_after ) );
				}
			}
		}

		/*
		 * If the server doesn't tell us how much we should wait, we do
		 * an exponential backoff, waiting increasingly longer times on
		 * each retry.
		 *
		 * "$this->retry" is 5 by default, so it behaves like this:
		 *
		 * Examples:
		 * - If `$this->retry_429` is 5, the delay will be 5 * 2^0 = 5 seconds.
		 * - If `$this->retry_429` is 4, the delay will be 5 * 2^1 = 10 seconds.
		 * - If `$this->retry_429` is 3, the delay will be 5 * 2^2 = 20 seconds.
		 * - If `$this->retry_429` is 2, the delay will be 5 * 2^3 = 40 seconds.
		 * - If `$this->retry_429` is 1, the delay will be 5 * 2^4 = 80 seconds.
		 * - If `$this->retry_429` is 0, the delay will be 5 * 2^5 = 160 seconds.
		 */
		if ( is_null( $retry_after ) ) {
			$retry_after = 5 * pow( 2, abs( $this->retry_429 - 5 ) );
		}

		// Ensure we wait at least 1 second.
		$retry_after = max( 1, $retry_after );

		// And no longer than 60 seconds.
		$retry_after = min( $max_wait, $retry_after );

		$retry_after += rand( 0, 5 ); // Add a random number of seconds to avoid all clients retrying at the same time.

		return $retry_after;
	}

	/**
	 * @return array<mixed> The array version of this class.
	 */
	public function to_array(): array {
		return [
			'url'                   => $this->url,
			'method'                => $this->method,
			'post_body'             => $this->post_body,
			'curl_opts'             => $this->curl_opts,
			'expected_status_codes' => $this->expected_status_codes,
		];
	}
}
