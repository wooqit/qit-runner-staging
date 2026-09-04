<?php

class PlaywrightResultTest extends \PHPUnit\Framework\TestCase {
	use \Spatie\Snapshots\MatchesSnapshots;

	public function make_sut(): \CI_CLI\Results\PlaywrightResults {
		$playwright_results = new \CI_CLI\Results\PlaywrightResults(
			$test_run_id = '',
			$ci_secret = '',
			$ci_staging_secret = '',
			$manager_host = '',
			$result_endpoint = '',
			$sut_version = '',
			$cancelled = '',
			$test_result = '',
			$workspace = '',
			$test_result_json = '',
			new \Symfony\Component\Console\Output\NullOutput(),
			$partial_path = '',
		);

		return $playwright_results;
	}

	public function test_parse_skipped_json() {
		$json = json_decode( file_get_contents( __DIR__ . '/data/skipped.json' ), true );

		$results = $this->make_sut();

		$converted = $results->convert_pw_to_puppeteer( $json );

		$this->assertMatchesJsonSnapshot( $converted );
	}
}