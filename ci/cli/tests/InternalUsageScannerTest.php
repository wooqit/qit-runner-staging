<?php

use CI_CLI\Commands\InternalUsageScanCommand;
use CI_CLI\InternalUsage\InternalUsageScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class InternalUsageScannerTest extends \PHPUnit\Framework\TestCase {
	private string $temporary_directory;

	protected function setUp(): void {
		parent::setUp();
		$this->temporary_directory = sys_get_temp_dir() . '/qit-internal-usage-' . uniqid( '', true );
		mkdir( $this->temporary_directory, 0777, true );
	}

	protected function tearDown(): void {
		$this->delete_directory( $this->temporary_directory );
		parent::tearDown();
	}

	public function test_detects_supported_reference_kinds_and_is_deterministic(): void {
		$this->write_file( 'plugin.php', <<<'PHP'
<?php

use Automattic\WooCommerce\Internal\{
	Features\FeaturesController as Controller,
	RegisterHooksInterface,
	function example_function as internal_function,
	const EXAMPLE_CONSTANT as INTERNAL_CONSTANT
};

interface InternalInterfaceConsumer extends RegisterHooksInterface {
}

#[\Automattic\WooCommerce\Internal\ExampleAttribute]
class InternalConsumer extends \Automattic\WooCommerce\Internal\ExampleBase implements RegisterHooksInterface {
	use \Automattic\WooCommerce\Internal\ExampleTrait;

	public Controller $controller;

	public function create( ?Controller $controller ): RegisterHooksInterface {
		$instance = new Controller();
		Controller::boot();
		$instance instanceof Controller;
		Controller::class;
		return $controller;
	}
}

enum InternalEnum implements RegisterHooksInterface {
	case Example;
}

function internal_type( \Automattic\WooCommerce\Internal\ExampleBase $value ): \Automattic\WooCommerce\Internal\ExampleBase {
	return $value;
}

$closure = function ( Controller $value ): RegisterHooksInterface {
	return $value;
};
$arrow = fn ( Controller $value ): RegisterHooksInterface => $value;

$aliased_function = internal_function();
$aliased_constant = INTERNAL_CONSTANT;
$dynamic = class_exists( 'Automattic\\WooCommerce\\Internal\\StringOnly' );
$callback = 'Automattic\\WooCommerce\\Internal\\StringOnly::run';
$not_exact = 'Automattic\\WooCommerce\\Internal\\StringOnly is internal';

/** @var \Automattic\WooCommerce\Internal\DocblockOnly $ignored */
$public = \Automattic\WooCommerce\Utilities\OrderUtil::class;
// Automattic\WooCommerce\Internal\CommentOnly must not be reported.
PHP
		);

		$scanner = new InternalUsageScanner();
		$first   = $scanner->scan( $this->temporary_directory );
		$second  = $scanner->scan( $this->temporary_directory );

		$this->assertSame( $first, $second );
		$this->assertSame( 'observed', $first['state'] );
		$this->assertTrue( $first['coverage']['complete'] );
		$this->assertSame( 1, $first['coverage']['files_scanned'] );
		$this->assertGreaterThan( 0, $first['summary']['occurrence_count'] );

		$kinds = array_values( array_unique( array_column( $first['findings'], 'kind' ) ) );
		sort( $kinds );
		$this->assertSame( [
			'attribute',
			'class_constant',
			'constant_access',
			'extends',
			'function_call',
			'implements',
			'import',
			'instanceof',
			'native_type',
			'new',
			'static_access',
			'string_reference',
			'trait_use',
		], $kinds );

		$symbols = array_column( $first['findings'], 'symbol' );
		$this->assertNotContains( 'Automattic\WooCommerce\Internal\DocblockOnly', $symbols );
		$this->assertNotContains( 'Automattic\WooCommerce\Internal\CommentOnly', $symbols );
		$this->assertNotContains( 'Automattic\WooCommerce\Utilities\OrderUtil', $symbols );
		$this->assertNotContains( 'Automattic\WooCommerce\Internal\StringOnly is internal', $symbols );
		$this->assertContains( 'Automattic\WooCommerce\Internal\StringOnly::run', $symbols );
	}

	public function test_scans_bundled_production_code_and_excludes_nonproduction_directories(): void {
		$this->write_file( 'vendor/runtime.php', $this->internal_new_expression( 'BundledVendorClass' ) );
		$this->write_file( 'vendor-prefixed/runtime.inc', $this->internal_new_expression( 'PrefixedClass' ) );
		$this->write_file( 'generated/runtime.phtml', $this->internal_new_expression( 'GeneratedClass' ) );

		foreach ( [ 'tests', 'test', 'spec', 'specs', 'examples', 'docs', 'node_modules', '.bzr', '.git', '.hg', '.svn' ] as $directory ) {
			$this->write_file( $directory . '/ignored.php', $this->internal_new_expression( 'Ignored' . md5( $directory ) ) );
		}

		$result = ( new InternalUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 3, $result['coverage']['files_scanned'] );
		$this->assertSame( 3, $result['summary']['occurrence_count'] );
		$this->assertSame( [ 'generated/runtime.phtml', 'vendor-prefixed/runtime.inc', 'vendor/runtime.php' ], array_column( $result['findings'], 'file' ) );
	}

	public function test_parse_failures_are_observed_as_incomplete_coverage(): void {
		$this->write_file( 'valid.php', $this->internal_new_expression( 'ValidClass' ) );
		$this->write_file( 'broken.php', "<?php function broken( {\n" );

		$result = ( new InternalUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 'observed', $result['state'] );
		$this->assertFalse( $result['coverage']['complete'] );
		$this->assertSame( 1, $result['coverage']['parse_failure_count'] );
		$this->assertSame( 'broken.php', $result['coverage']['parse_failures'][0]['file'] );
		$this->assertSame( 1, $result['summary']['occurrence_count'] );
	}

	public function test_requested_php_74_semantics_accept_legacy_identifiers_and_offsets(): void {
		$this->write_file( 'legacy.php', <<<'PHP'
<?php
class Match {}
$value = 'legacy';
$first = $value{0};
new \Automattic\WooCommerce\Internal\LegacyConsumer();
PHP
		);

		$result = ( new InternalUsageScanner() )->scan( $this->temporary_directory, '7.4' );

		$this->assertSame( 'observed', $result['state'] );
		$this->assertTrue( $result['coverage']['complete'] );
		$this->assertSame( 0, $result['coverage']['parse_failure_count'] );
		$this->assertSame( '7.4', $result['metadata']['php_version'] );
		$this->assertSame( 1, $result['summary']['occurrence_count'] );
	}

	public function test_invalid_requested_php_version_is_unavailable(): void {
		$this->write_file( 'plugin.php', $this->internal_new_expression( 'InvalidVersion' ) );

		$result = ( new InternalUsageScanner() )->scan( $this->temporary_directory, 'not-a-version' );

		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( 'invalid_php_version', $result['reason_code'] );
	}

	public function test_missing_or_empty_roots_are_unavailable(): void {
		$scanner = new InternalUsageScanner();

		$missing = $scanner->scan( $this->temporary_directory . '/missing' );
		$this->assertSame( 'unavailable', $missing['state'] );
		$this->assertSame( 'sut_unavailable', $missing['reason_code'] );
		$this->assertFalse( $missing['coverage']['complete'] );

		$empty = $scanner->scan( $this->temporary_directory );
		$this->assertSame( 'unavailable', $empty['state'] );
		$this->assertSame( 'no_php_files', $empty['reason_code'] );
		$this->assertStringContainsString( 'No readable PHP source files', $empty['reason'] );
	}

	public function test_command_writes_standalone_output_and_merges_without_changing_compatibility_result(): void {
		$this->write_file( 'plugin.php', $this->internal_new_expression( 'CommandClass' ) );
		$standalone_path = $this->temporary_directory . '/standalone.json';
		$command         = new CommandTester( new InternalUsageScanCommand() );

		$this->assertSame( Command::SUCCESS, $command->execute( [
			'source-directory' => $this->temporary_directory,
			'--php-version'    => '7.4',
			'--output'         => $standalone_path,
		] ) );
		$standalone = json_decode( (string) file_get_contents( $standalone_path ), true );
		$this->assertSame( 'observed', $standalone['state'] );
		$this->assertSame( '7.4', $standalone['metadata']['php_version'] );

		$compatibility_path = $this->temporary_directory . '/compatibility.json';
		file_put_contents( $compatibility_path, json_encode( [
			'state'   => 'observed',
			'summary' => [ 'introduced_count' => 0 ],
		] ) );

		$this->assertSame( Command::SUCCESS, $command->execute( [
			'source-directory' => $this->temporary_directory,
			'--merge-into'     => $compatibility_path,
		] ) );
		$merged = json_decode( (string) file_get_contents( $compatibility_path ), true );
		$this->assertSame( 'observed', $merged['state'] );
		$this->assertSame( 0, $merged['summary']['introduced_count'] );
		$this->assertSame( 'qit-internal-usage', $merged['observations']['internal_namespace_usage']['tool']['name'] );
	}

	public function test_command_records_unavailable_observation_without_failing(): void {
		$output_path = $this->temporary_directory . '/unavailable.json';
		$command     = new CommandTester( new InternalUsageScanCommand() );

		$this->assertSame( Command::SUCCESS, $command->execute( [
			'source-directory' => $this->temporary_directory . '/missing',
			'--output'         => $output_path,
		] ) );

		$result = json_decode( (string) file_get_contents( $output_path ), true );
		$this->assertSame( 'unavailable', $result['state'] );
	}

	public function test_committed_plugin_fixtures_produce_auditable_positive_and_negative_results(): void {
		$fixture_root = __DIR__ . '/fixtures/internal-usage';
		$scanner      = new InternalUsageScanner();

		$flagged = $scanner->scan( $fixture_root . '/flagged-plugin' );
		$this->assertSame( 'observed', $flagged['state'] );
		$this->assertTrue( $flagged['coverage']['complete'] );
		$this->assertSame( 1, $flagged['coverage']['files_scanned'] );
		$this->assertSame( 3, $flagged['summary']['occurrence_count'] );
		$this->assertSame( 2, $flagged['summary']['unique_symbol_count'] );

		$kinds = array_column( $flagged['findings'], 'kind' );
		sort( $kinds );
		$this->assertSame( [ 'class_constant', 'import', 'string_reference' ], $kinds );
		$this->assertSame( [ 'flagged-plugin.php' ], array_values( array_unique( array_column( $flagged['findings'], 'file' ) ) ) );
		foreach ( $flagged['findings'] as $finding ) {
			$this->assertNotSame( '', $finding['symbol'] );
			$this->assertGreaterThan( 0, $finding['line'] );
		}

		$clean = $scanner->scan( $fixture_root . '/clean-plugin' );
		$this->assertSame( 'observed', $clean['state'] );
		$this->assertTrue( $clean['coverage']['complete'] );
		$this->assertSame( 1, $clean['coverage']['files_scanned'] );
		$this->assertSame( 0, $clean['summary']['occurrence_count'] );
		$this->assertSame( 0, $clean['summary']['unique_symbol_count'] );
		$this->assertSame( [], $clean['findings'] );
	}

	private function internal_new_expression( string $class_name ): string {
		return sprintf( "<?php\nnew \\Automattic\\WooCommerce\\Internal\\%s();\n", $class_name );
	}

	private function write_file( string $relative_path, string $contents ): void {
		$path      = $this->temporary_directory . '/' . $relative_path;
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}
		file_put_contents( $path, $contents );
	}

	private function delete_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
