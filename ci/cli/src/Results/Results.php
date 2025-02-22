<?php

namespace CI_CLI\Results;

use Symfony\Component\Console\Output\OutputInterface;

abstract class Results {
	protected string $test_run_id;
	protected string $ci_secret;
	protected string $ci_staging_secret;
	protected string $manager_host;
	protected string $result_endpoint;
	protected string $sut_version;
	protected bool $cancelled;
	protected string $test_result;
	protected string $workspace;
	protected string $test_result_json;
	protected OutputInterface $output;

	protected string $partial_path;

	public function __construct(
		string $test_run_id,
		string $ci_secret,
		string $ci_staging_secret,
		string $manager_host,
		string $result_endpoint,
		string $sut_version,
		bool $cancelled,
		string $test_result,
		string $workspace,
		string $test_result_json,
		OutputInterface $output,
		string $partial_path = ''
	) {
		$this->test_run_id       = $test_run_id;
		$this->ci_secret         = $ci_secret;
		$this->ci_staging_secret = $ci_staging_secret;
		$this->manager_host      = $manager_host;
		$this->result_endpoint   = $result_endpoint;
		$this->sut_version       = $sut_version;
		$this->cancelled         = $cancelled;
		$this->test_result       = $test_result;
		$this->workspace         = $workspace;
		$this->test_result_json  = $test_result_json;
		$this->output            = $output;
		$this->partial_path      = $partial_path;
	}

	protected function get_url(): string {
		return sprintf( 'https://%s/%s', $this->manager_host, $this->result_endpoint );
	}

	protected function get_test_status(): string {
		$status = $this->test_result === 'success' ? 'success' : 'failed';

		if ( $this->cancelled == true ) {
			$status = 'cancelled';
		}

		return $status;
	}

	protected function get_ci_secret(): string {
		if ( stripos( $this->manager_host, 'stagingcompatibilitydashboard' ) !== false ) {
			return $this->ci_staging_secret;
		} else {
			return $this->ci_secret;
		}
	}

	public static function get_aws_presign_data(): string {
		$file = getenv( 'WORKSPACE' ) . "/bin/results/presign.json";

		if ( file_exists( $file ) ) {
			return file_get_contents( $file );
		} else {
			echo "$file does not exist.\n";

			return '';
		}
	}

	abstract protected function get_test_result_json():string;

	abstract public function send_results(): string;
}