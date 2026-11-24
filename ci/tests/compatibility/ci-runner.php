<?php

if ( ! class_exists( CI_Runner::class ) ) {
	class CI_Runner {
		public static function cd_manager_request( string $endpoint, array $data, array $expected_status_code = [ 200 ] ): string {
			$env = getenv();

			$required_envs = [
				'CI_SECRET',
				'CI_STAGING_SECRET',
				'MANAGER_HOST',
			];

			foreach ( $required_envs as $required_env ) {
				if ( ! isset( $env[ $required_env ] ) ) {
					echo "Missing required env: $required_env\n";
					die( 1 );
				}
			}

			if ( stripos( $env['MANAGER_HOST'], 'stagingcompatibilitydashboard' ) !== false ) {
				$secret = $env['CI_STAGING_SECRET'];
			} else {
				$secret = $env['CI_SECRET'];
			}

			if ( empty( $secret ) ) {
				echo "Please check that your repo has the CI_SECRET and CI_STAGING_SECRET configured in the Secrets.\n";
				die( 1 );
			}

			$data['ci_secret'] = $secret;
			$data['client']    = 'ci_runner';

			// Normalize host: Remove trailing slash, trim and lowercase.
			$host = strtolower( trim( rtrim( $env['MANAGER_HOST'], '/' ) ) );

			// Add protocol if one is not present.
			if ( substr( $host, 0, 4 ) !== 'http' ) {
				$host = 'https://' . $host;
			}

			$url  = sprintf( '%s/wp-json/cd/v1/%s', $host, $endpoint );
			$data = json_encode( $data );

			$retries = 0;
			retry:

			$curl = curl_init();
			curl_setopt_array( $curl, [
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_USERAGENT      => 'curl/7.68.0',
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'Accept: application/json'
				],
				CURLOPT_POSTFIELDS     => $data
			] );

			echo sprintf( "Sending request to the Manager. URL: %s Body: %s", $url, $data );
			$start    = microtime( true );
			$response = curl_exec( $curl );
			echo sprintf( "Received response in %s seconds. Response Length: %s\n", number_format( microtime( true ) - $start, 2 ), strlen( json_encode( $response ) ) );

			if ( ! in_array( curl_getinfo( $curl, CURLINFO_HTTP_CODE ), $expected_status_code ) ) {
				// If it's a 429, wait between 2 and 5 seconds and retry, up to 2 times.
				if ( curl_getinfo( $curl, CURLINFO_HTTP_CODE ) === 429 && $retries < 2 ) {
					$retries ++;
					$wait = rand( 2, 5 );
					echo sprintf( "Received a 429 response. Waiting %s seconds and retrying. Retry %s of 2\n", $wait, $retries );
					sleep( $wait );
					goto retry;
				}

				echo sprintf( "Did not receive a successful response from the Manager. Expected: %s Got: %s Response: %s\n", implode( ', ', $expected_status_code ), curl_getinfo( $curl, CURLINFO_HTTP_CODE ), json_encode( $response ) );
				throw new Exception();
			}

			curl_close( $curl );

			return (string) $response;
		}
	}
}