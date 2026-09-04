<?php

namespace CI_CLI\Commands;

use CI_CLI\RequestBuilder;
use CI_CLI\Results\GenericResults;
use CI_CLI\Results\PlaywrightResults;
use CI_CLI\Results\Results;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function CI_CLI\get_manager_secret;
use function CI_CLI\validate_env_vars;

class UpdateReportCommand extends Command {

	private array $expected_result_vars =  [
		'TEST_RUN_ID',
		'CI_SECRET',
		'CI_STAGING_SECRET',
		'MANAGER_HOST',
		'WORKSPACE',
		'RESULTS_ENDPOINT',
	];

	protected function configure(): void {
		$this
			->setName( 'update-report' )
			->setDescription( 'Set the report URL for a given test run ID in the QIT Manager.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		validate_env_vars( $this->expected_result_vars );

		$url       = getenv( 'RESULTS_ENDPOINT' );
		$ci_secret = get_manager_secret();

		$response = ( new RequestBuilder( $url, $output ) )
			->with_method( 'POST' )
			->with_post_body( [
				'test_run_id' => getenv('TEST_RUN_ID' ),
				'aws_allure' => Results::get_aws_presign_data(),
				'ci_secret'   => $ci_secret,
			] )
			->with_expected_status_codes( [ 200 ] )
			->with_timeout_in_seconds( 15 )
			->with_headers( [
				'Content-Type: application/json',
				'Accept: application/json'
			] )
			->request();

		$output->writeln( $response );

		return Command::SUCCESS;
	}
}