<?php

namespace CI_CLI\Commands;

use CI_CLI\RequestBuilder;
use CI_CLI\Results\GenericResults;
use CI_CLI\Results\PlaywrightResults;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function CI_CLI\get_manager_secret;
use function CI_CLI\validate_env_vars;

class NotifyCommand extends Command {

	private array $expected_status_vars =  [
		'TEST_RUN_ID',
		'TEST_RUN_HASH',
		'CI_SECRET',
		'CI_STAGING_SECRET',
		'MANAGER_HOST',
		'RESULTS_ENDPOINT',
		'WORKFLOW_ID',
		'WORKSPACE',
	];

	private array $expected_result_vars =  [
		'TEST_RUN_ID',
		'CI_SECRET',
		'CI_STAGING_SECRET',
		'CI_STAGING_SECRET',
		'MANAGER_HOST',
		'RESULTS_ENDPOINT',
		'CANCELLED',
		'TEST_RESULT_JSON',
		'TEST_RESULT',
		'WORKSPACE',
	];

	protected function configure(): void {
		$this
			->setName( 'notify' )
			->setDescription( 'Notify the QIT Manager about a test run.' )
			->addOption( 'status', 's', InputOption::VALUE_NONE, 'Notify the manager that the test is running.' )
			->addOption( 'result', 'r', InputOption::VALUE_NONE, 'Notify the manager that the test has finished.')
			->addOption( 'generic', 'g', InputOption::VALUE_NONE, 'Used with the result option to send generic results.' )
			->addOption( 'playwright', 'p', InputOption::VALUE_NONE, 'Used with the result option to send playwright results.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		if ( $input->getOption( 'status' ) ) {
			validate_env_vars( $this->expected_status_vars );

			$url       = sprintf( "https://%s/%s", getenv( 'MANAGER_HOST' ), getenv( 'RESULTS_ENDPOINT' ) );
			$ci_secret = get_manager_secret();

			$response = ( new RequestBuilder( $url, $output ) )
				->with_method( 'POST' )
				->with_post_body( [
					'test_run_id' => getenv('TEST_RUN_ID' ),
					'workflow_id'  => getenv('WORKFLOW_ID' ),
					'hash'        => getenv('TEST_RUN_HASH' ),
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
		}

		if ( $input->getOption( 'result' ) ) {
			validate_env_vars( $this->expected_result_vars );

			if ( $input->getOption('playwright') ) {
				validate_env_vars( [ 'PARTIAL_PATH' ] );
				$response = ( new PlaywrightResults (
					getenv( 'TEST_RUN_ID' ),
					getenv( 'CI_SECRET' ),
					getenv( 'CI_STAGING_SECRET' ),
					getenv( 'MANAGER_HOST' ),
					getenv( 'RESULTS_ENDPOINT' ),
					getenv( 'SUT_VERSION' ),
					getenv( 'CANCELLED' ),
					getenv( 'TEST_RESULT' ),
					getenv( 'WORKSPACE' ),
					getenv( 'TEST_RESULT_JSON' ),
					$output,
					getenv( 'PARTIAL_PATH' )
				) )->send_results();
			} else {
				$response = ( new GenericResults(
					getenv( 'TEST_RUN_ID' ),
					getenv( 'CI_SECRET' ),
					getenv( 'CI_STAGING_SECRET' ),
					getenv( 'MANAGER_HOST' ),
					getenv( 'RESULTS_ENDPOINT' ),
					getenv( 'SUT_VERSION' ),
					getenv( 'CANCELLED' ),
					getenv( 'TEST_RESULT' ),
					getenv( 'WORKSPACE' ),
					getenv( 'TEST_RESULT_JSON' ),
					$output
				) )->send_results();
			}

			$output->writeln( "Response: $response" );
		}


		return Command::SUCCESS;
	}
}