<?php

use CI_CLI\Commands\NotifyCommand;
use CI_CLI\RequestBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class NotifyCommandTest extends \PHPUnit\Framework\TestCase {

	private const ENV_VARS = [ 'TEST_RUN_ID', 'CI_SECRET', 'CI_STAGING_SECRET', 'MANAGER_HOST', 'SUT_VERSION' ];

	/** @var array<string,string|false> */
	private array $original_env = [];

	protected function setUp(): void {
		parent::setUp();
		foreach ( self::ENV_VARS as $variable ) {
			$this->original_env[ $variable ] = getenv( $variable );
		}
		putenv( 'TEST_RUN_ID=123' );
		putenv( 'CI_SECRET=production-secret' );
		putenv( 'CI_STAGING_SECRET=staging-secret' );
		putenv( 'MANAGER_HOST=qit.example.com' );
	}

	protected function tearDown(): void {
		foreach ( $this->original_env as $variable => $value ) {
			putenv( $value === false ? $variable : "{$variable}={$value}" );
		}
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sut_version_request( string $sut_version ): array {
		$method = new \ReflectionMethod( NotifyCommand::class, 'make_sut_version_request' );
		$method->setAccessible( true );

		$request = $method->invoke( new NotifyCommand(), $sut_version, new BufferedOutput() );
		$this->assertInstanceOf( RequestBuilder::class, $request );

		return $request->to_array();
	}

	/**
	 * RequestBuilder sends the body as JSON. Without the header WordPress reads it as form
	 * data, sees none of the parameters and rejects the call.
	 */
	public function test_sut_version_request_is_sent_as_json(): void {
		$request = $this->sut_version_request( '4.4.0' );

		$this->assertContains( 'Content-Type: application/json', $request['headers'] );
	}

	public function test_sut_version_request_targets_the_sut_version_endpoint(): void {
		$request = $this->sut_version_request( '4.4.0' );

		$this->assertSame( 'https://qit.example.com/wp-json/cd/v1/test-sut-version', $request['url'] );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame(
			[
				'test_run_id' => '123',
				'sut_version' => '4.4.0',
				'ci_secret'   => 'production-secret',
			],
			$request['post_body']
		);
	}

	public function test_reports_the_sut_version_it_was_given(): void {
		putenv( 'SUT_VERSION= 4.4.0 ' );
		$command = $this->command_that_records_requests();

		$this->assertSame( Command::SUCCESS, ( new CommandTester( $command ) )->execute( [ '--sut-version' => true ] ) );

		$this->assertCount( 1, $command->sent );
		$this->assertSame( 'https://qit.example.com/wp-json/cd/v1/test-sut-version', $command->sent[0]['url'] );
		$this->assertSame( '4.4.0', $command->sent[0]['post_body']['sut_version'] );
	}

	/**
	 * @testWith [""]
	 *           ["Undefined"]
	 *           [null]
	 */
	public function test_reports_nothing_without_a_detected_version( ?string $sut_version ): void {
		putenv( $sut_version === null ? 'SUT_VERSION' : "SUT_VERSION={$sut_version}" );
		$command = $this->command_that_records_requests();
		$tester  = new CommandTester( $command );

		$this->assertSame( Command::SUCCESS, $tester->execute( [ '--sut-version' => true ] ) );

		$this->assertSame( [], $command->sent );
		$this->assertStringContainsString( 'No SUT version was detected', $tester->getDisplay() );
	}

	/**
	 * @return NotifyCommand&object{sent: array<int,array<string,mixed>>}
	 */
	private function command_that_records_requests(): NotifyCommand {
		return new class() extends NotifyCommand {
			/** @var array<int,array<string,mixed>> */
			public array $sent = [];

			protected function make_sut_version_request( string $sut_version, OutputInterface $output ): RequestBuilder {
				$request = parent::make_sut_version_request( $sut_version, $output );
				$sent    = &$this->sent;

				return new class( $request, $sent ) extends RequestBuilder {
					private RequestBuilder $request;
					/** @var array<int,array<string,mixed>> */
					private array $sent;

					/**
					 * @param array<int,array<string,mixed>> $sent
					 */
					public function __construct( RequestBuilder $request, array &$sent ) {
						parent::__construct( "", new BufferedOutput() );
						$this->request = $request;
						$this->sent    = &$sent;
					}

					public function request(): string {
						$this->sent[] = $this->request->to_array();

						return '{"success":true}';
					}
				};
			}
		};
	}
}
