<?php

use CI_CLI\Commands\DownloadPluginCommand;
use CI_CLI\PluginDownloadException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DownloadPluginCommandWithResponse extends DownloadPluginCommand {
	/** @var array{contents:string|false,status:int,transport_error:bool} */
	private array $response;

	/** @param array{contents:string|false,status:int,transport_error:bool} $response */
	public function __construct( array $response ) {
		$this->response = $response;
		parent::__construct();
	}

	/** @return array{contents:string|false,status:int,transport_error:bool} */
	protected function request_all_plugins_artifact( string $url, string $token ): array {
		return $this->response;
	}
}

class DownloadPluginCommandTest extends \PHPUnit\Framework\TestCase {

	private function failure_path(): string {
		return sys_get_temp_dir() . '/qit-download-failure-' . uniqid( '', true ) . '.json';
	}

	/**
	 * @param array<int,mixed> $args
	 * @return mixed
	 */
	private function invoke( string $method, array $args, ?DownloadPluginCommand $command = null ) {
		$ref = new \ReflectionMethod( DownloadPluginCommand::class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $command ?? new DownloadPluginCommand(), $args );
	}

	protected function tearDown(): void {
		foreach ( [ 'TOKEN', 'QIT_FAILURE_OUTPUT', 'PLUGINS_ZIPS', 'PLUGINS_JSON', 'THEME_DIRECTORY', 'PLUGIN_DIRECTORY', 'CI_ENCRYPTION_KEY' ] as $variable ) {
			putenv( $variable );
		}
		parent::tearDown();
	}

	public function test_download_contents_returns_primary_contents_when_reachable(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'qit-zip' );
		file_put_contents( $tmp, 'zip-bytes' );

		$contents = $this->invoke( 'download_contents', [ 'file://' . $tmp, [ 'slug' => 'foo' ] ] );

		$this->assertSame( 'zip-bytes', $contents );
		unlink( $tmp );
	}

	public function test_download_contents_throws_for_non_all_plugins_when_primary_fails(): void {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Could not download foo: artifact request failed.' );

		$this->invoke( 'download_contents', [ 'file:///qit/does-not-exist.zip', [ 'slug' => 'foo' ] ] );
	}

	public function test_all_plugins_artifact_requires_token(): void {
		putenv( 'TOKEN' );

		$this->expectException( PluginDownloadException::class );
		$this->expectExceptionMessage( 'missing GitHub token' );

		// Primary URL is irrelevant for all_plugins; it must route straight to the API path.
		$this->invoke( 'download_contents', [
			'file:///qit/ignored.zip',
			[
				'slug'         => 'foo',
				'artifact_ref' => [
					'source'   => 'all_plugins',
					'repo'     => 'woocommerce/all-plugins',
					'sha'      => 'abc123',
					'zip_path' => 'product-packages/foo/foo.zip',
				],
			],
		] );
	}

	public function test_all_plugins_artifact_requires_complete_metadata(): void {
		putenv( 'TOKEN=secret' );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'incomplete all-plugins artifact metadata' );

		$this->invoke( 'download_contents', [
			'file:///qit/does-not-exist.zip',
			[
				'slug'         => 'foo',
				'artifact_ref' => [
					'source' => 'all_plugins',
					'repo'   => 'woocommerce/all-plugins',
					// Missing sha and zip_path.
				],
			],
		] );
	}

	/**
	 * @dataProvider all_plugins_http_failure_provider
	 */
	public function test_all_plugins_artifact_classifies_http_failures( int $status, bool $transport_error, string $expected_code ): void {
		putenv( 'TOKEN=do-not-leak-this-token' );
		$command = new DownloadPluginCommandWithResponse( [
			'contents'        => false,
			'status'          => $status,
			'transport_error' => $transport_error,
		] );

		try {
			$this->invoke( 'download_contents', [
				'https://example.invalid/ignored.zip',
				[
					'slug'         => 'foo',
					'artifact_ref' => [
						'source'   => 'all_plugins',
						'repo'     => 'woocommerce/all-plugins',
						'sha'      => 'abc123',
						'zip_path' => 'product-packages/foo/foo.zip',
					],
				],
			], $command );
			$this->fail( 'Expected the artifact request to fail.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( $expected_code, $e->get_failure()['code'] );
			$this->assertStringNotContainsString( 'do-not-leak-this-token', $e->getMessage() );
		}
	}

	/** @return array<string,array{int,bool,string}> */
	public function all_plugins_http_failure_provider(): array {
		return [
			'unauthorized' => [ 401, false, 'all_plugins_authentication_failed' ],
			'forbidden'    => [ 403, false, 'all_plugins_forbidden' ],
			'not found'    => [ 404, false, 'all_plugins_not_found' ],
			'other HTTP'   => [ 500, false, 'all_plugins_http_error' ],
			'transport'    => [ 0, true, 'download_transport_error' ],
		];
	}

	public function test_all_plugins_artifact_returns_successful_response(): void {
		putenv( 'TOKEN=secret' );
		$command = new DownloadPluginCommandWithResponse( [
			'contents'        => 'zip-bytes',
			'status'          => 200,
			'transport_error' => false,
		] );

		$result = $this->invoke( 'download_contents', [
			'https://example.invalid/ignored.zip',
			[
				'slug'         => 'foo',
				'artifact_ref' => [
					'source'   => 'all_plugins',
					'repo'     => 'woocommerce/all-plugins',
					'sha'      => 'abc123',
					'zip_path' => 'product-packages/foo/foo.zip',
				],
			],
		], $command );

		$this->assertSame( 'zip-bytes', $result );
	}

	public function test_structured_failure_file_contains_only_sanitized_fields(): void {
		$path = $this->failure_path();
		putenv( "QIT_FAILURE_OUTPUT={$path}" );

		$this->invoke( 'write_failure', [ [
			'stage'   => 'plugin_download',
			'code'    => 'all_plugins_authentication_failed',
			'message' => 'Could not download foo: all-plugins artifact request failed (HTTP 401).',
		] ] );

		$failure = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( [ 'stage', 'code', 'message' ], array_keys( $failure ) );
		$this->assertSame( 'all_plugins_authentication_failed', $failure['code'] );
		$this->assertStringNotContainsString( 'Authorization', (string) file_get_contents( $path ) );
		unlink( $path );
	}

	public function test_structured_failure_preserves_the_first_recorded_failure(): void {
		$path     = $this->failure_path();
		$original = [
			'stage'   => 'analysis',
			'code'    => 'woocommerce_artifact_unavailable',
			'message' => 'WooCommerce artifact download failed.',
		];
		file_put_contents( $path, json_encode( $original ) );
		putenv( "QIT_FAILURE_OUTPUT={$path}" );

		$this->invoke( 'write_failure', [ [
			'stage'   => 'plugin_download',
			'code'    => 'plugin_download_failed',
			'message' => 'Secondary plugin download failure.',
		] ] );

		$this->assertSame( $original, json_decode( (string) file_get_contents( $path ), true ) );
		unlink( $path );
	}

	public function test_execute_records_setup_failure_when_plugin_metadata_is_invalid(): void {
		$path = $this->failure_path();
		putenv( "QIT_FAILURE_OUTPUT={$path}" );
		putenv( 'PLUGINS_ZIPS={}' );
		putenv( 'PLUGINS_JSON=not-json' );
		putenv( 'THEME_DIRECTORY=/tmp' );
		putenv( 'PLUGIN_DIRECTORY=/tmp' );
		putenv( 'CI_ENCRYPTION_KEY=do-not-leak-this-key' );

		$tester = new CommandTester( new DownloadPluginCommand() );
		$this->assertSame( Command::FAILURE, $tester->execute( [] ) );
		$failure = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( 'setup', $failure['stage'] );
		$this->assertSame( 'plugin_download_failed', $failure['code'] );
		$this->assertStringNotContainsString( 'do-not-leak-this-key', $tester->getDisplay() . file_get_contents( $path ) );
		unlink( $path );
	}

	public function test_encode_path_preserves_slashes_and_trims_edges(): void {
		$this->assertSame(
			'woocommerce/all-plugins',
			$this->invoke( 'encode_path', [ '/woocommerce/all-plugins/' ] )
		);
		$this->assertSame(
			'product-packages/foo/foo.zip',
			$this->invoke( 'encode_path', [ 'product-packages/foo/foo.zip' ] )
		);
		// Spaces (and other unsafe chars) must be percent-encoded, slashes kept.
		$this->assertSame(
			'a/b%20c.zip',
			$this->invoke( 'encode_path', [ 'a/b c.zip' ] )
		);
	}
}
