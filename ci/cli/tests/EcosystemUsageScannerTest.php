<?php

use CI_CLI\Commands\EcosystemUsageScanCommand;
use CI_CLI\EcosystemImpact\EcosystemUsageScanner;
use CI_CLI\EcosystemImpact\SurfaceNormalizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class EcosystemUsageScannerTest extends \PHPUnit\Framework\TestCase {
	private string $temporary_directory;

	protected function setUp(): void {
		parent::setUp();
		$this->temporary_directory = sys_get_temp_dir() . '/qit-ecosystem-usage-' . uniqid( '', true );
		mkdir( $this->temporary_directory, 0777, true );
	}

	protected function tearDown(): void {
		$this->delete_directory( $this->temporary_directory );
		parent::tearDown();
	}

	public function test_detects_structural_references_functions_and_consumer_symbols(): void {
		$this->write_file( 'plugin.php', <<<'PHP'
<?php

namespace Example;

use Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Admin\UnusedImport;

interface PluginFeed extends FeedInterface {}

class IndirectStripeFeed implements PluginFeed {}

class StripeFeed implements FeedInterface {
	use \Automattic\WooCommerce\Utilities\ExampleTrait;

	public OrderUtil $orders;

	public function create( ?\WC_Order $order ): \WooCommerce {
		$instance = new OrderUtil();
		OrderUtil::custom_orders_table_usage_is_enabled();
		$order instanceof \WC_Order;
		\WC_Order::class;
		wc_get_order( 123 );
		woocommerce_form_field( 'name' );
		return $instance;
	}
}

class ChildOrder extends \WC_Order {}

class ImportOnly {
	/** @var \WC_Product Comments and docblocks are deliberately excluded. */
	public function ignored(): void {
		$class = 'Automattic\\WooCommerce\\Utilities\\StringReference';
		$dynamic = $class::method();
	}
}
PHP
		);

		$scanner = new EcosystemUsageScanner();
		$first   = $scanner->scan( $this->temporary_directory, '7.4' );
		$second  = $scanner->scan( $this->temporary_directory, '7.4' );

		$this->assertSame( $first, $second );
		$this->assertSame( 'observed', $first['state'] );
		$this->assertSame( 2, $first['schema_version'] );
		$this->assertSame( '5', $first['tool']['version'] );
		$this->assertSame( 'nikic/php-parser', $first['runtime']['parser']['name'] );
		$this->assertSame( '7.4', $first['runtime']['parser']['target_version'] );
		$this->assertSame( 1, $first['coverage']['files_discovered'] );
		$this->assertSame( 1, $first['coverage']['files_scanned'] );
		$this->assertSame( 0, $first['coverage']['excluded_directory_count'] );
		$this->assertSame( [], $first['coverage']['excluded_directories'] );
		$this->assertFalse( $first['coverage']['excluded_directories_truncated'] );
		$this->assertTrue( $first['coverage']['complete'] );

		$keys = array_map( static function ( array $usage ): string {
			return $usage['usage_kind'] . ':' . $usage['surface'];
		}, $first['usages'] );

		$this->assertContains( 'class_implement:Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface', $keys );
		$this->assertContains( 'class_extend:Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface', $keys );
		$this->assertContains( 'trait_use:Automattic\WooCommerce\Utilities\ExampleTrait', $keys );
		$this->assertContains( 'class_extend:WC_Order', $keys );
		$this->assertContains( 'class_ref:Automattic\WooCommerce\Utilities\OrderUtil', $keys );
		$this->assertContains( 'class_ref:WC_Order', $keys );
		$this->assertContains( 'class_ref:WooCommerce', $keys );
		$this->assertContains( 'function:wc_get_order', $keys );
		$this->assertContains( 'function:woocommerce_form_field', $keys );
		$this->assertNotContains( 'class_ref:Automattic\WooCommerce\Utilities\StringReference', $keys );
		$this->assertNotContains( 'class_ref:Automattic\WooCommerce\Admin\UnusedImport', $keys );

		$feed_usage = $this->find_usage(
			$first['usages'],
			'Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface',
			'class_implement'
		);
		$this->assertSame( 'Example\StripeFeed', $feed_usage['consumer_symbol'] );
		$this->assertSame( 'extension', $feed_usage['origin'] );

		$interface_extension = $this->find_usage(
			$first['usages'],
			'Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface',
			'class_extend'
		);
		$this->assertSame( 'Example\PluginFeed', $interface_extension['consumer_symbol'] );
		$this->assertSame( 'extension', $interface_extension['origin'] );
	}

	public function test_preserves_case_distinct_class_constant_members_and_witnesses(): void {
		$this->write_file( 'constants.php', <<<'PHP'
<?php

class ConstantConsumer {
	public function read(): void {
		\WC_Order::Status;
		\WC_Order::status;
	}
}
PHP
		);

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory, '8.3' );
		$rows   = array_values( array_filter( $result['usages'], static function ( array $usage ): bool {
			return $usage['surface_key'] === 'wc_order'
				&& $usage['usage_kind'] === 'class_ref'
				&& isset( $usage['member'] );
		} ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( [ 'Status', 'status' ], array_column( $rows, 'member' ) );
		$this->assertSame( [ 5, 6 ], array_column( $rows, 'line' ) );
	}

	public function test_excludes_class_like_and_function_symbols_declared_anywhere_in_the_scanned_tree(): void {
		$this->write_file( 'a-references.php', <<<'PHP'
<?php

new \WC_Stripe_Helper();
\wc_stripe_helper::boot();

function consume_stripe_helper( \WC_Stripe_Helper $helper ): \WC_Stripe_Helper {
	return $helper;
}

class WC_Stripe_Consumer implements \WC_Stripe_Contract {
	use \WC_Stripe_Feature;

	public function mode(): \WC_Stripe_Mode {
		return \WC_Stripe_Mode::ACTIVE;
	}
}

new \Automattic\WooCommerce\GoogleListingsAndAds\Helper();
\Automattic\WooCommerce\GoogleListingsAndAds\Helper::boot();

new \WC_Order();
wc_get_order( 123 );
WC_STRIPE_GET_SETTINGS();
PHP
		);
		$this->write_file( 'z-declarations.php', <<<'PHP'
<?php

namespace {
	class WC_Stripe_Helper {}
	class WC_Get_Order {}
	function wc_stripe_get_settings() {}
	interface WC_Stripe_Contract {}
	trait WC_Stripe_Feature {}
	enum WC_Stripe_Mode {
		case ACTIVE;
	}
}

namespace Automattic\WooCommerce\GoogleListingsAndAds {
	class Helper {}
}
PHP
		);

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 'observed', $result['state'] );
		$this->assertSame( 2, $result['summary']['usage_count'] );
		$this->assertSame( 2, $result['summary']['unique_surface_count'] );
		$this->assertEqualsCanonicalizing( [ 'WC_Order', 'wc_get_order' ], array_column( $result['usages'], 'surface' ) );
		$this->assertNotContains( 'wc_stripe_get_settings', array_column( $result['usages'], 'surface_key' ) );
	}

	public function test_marks_bundled_code_and_excludes_nonproduction_directories(): void {
		$this->write_file( 'vendor/runtime.php', "<?php new \\WC_Order();\n" );
		$this->write_file( 'vendor-prefixed/runtime.inc', "<?php wc_get_order( 1 );\n" );
		$this->write_file( 'src/runtime.phtml', "<?php new \\WC_Product();\n" );

		foreach ( [ 'tests', 'test', 'spec', 'specs', 'examples', 'docs', 'node_modules', '.git' ] as $directory ) {
			$this->write_file( $directory . '/ignored.php', "<?php new \\WC_Coupon();\n" );
		}
		$this->write_file( 'src/spec/runtime.php', "<?php new \\WC_Shipping();\n" );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 3, $result['coverage']['files_discovered'] );
		$this->assertSame( 9, $result['coverage']['excluded_directory_count'] );
		$this->assertSame(
			[ '.git', 'docs', 'examples', 'node_modules', 'spec', 'specs', 'src/spec', 'test', 'tests' ],
			$result['coverage']['excluded_directories']
		);
		$this->assertFalse( $result['coverage']['excluded_directories_truncated'] );
		$this->assertTrue( $result['coverage']['complete'] );
		$this->assertSame( 2, $result['summary']['bundled_usage_count'] );
		$this->assertSame( 1, $result['summary']['extension_usage_count'] );
		$this->assertEqualsCanonicalizing( [ 'extension', 'bundled', 'bundled' ], array_column( $result['usages'], 'origin' ) );
	}

	public function test_excluded_directory_evidence_is_bounded_and_deterministic(): void {
		$this->write_file( 'plugin.php', "<?php new \\WC_Order();\n" );
		for ( $index = 0; $index < 105; $index ++ ) {
			$this->write_file( sprintf( 'package-%03d/tests/ignored.php', $index ), "<?php new \\WC_Coupon();\n" );
		}

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 105, $result['coverage']['excluded_directory_count'] );
		$this->assertCount( 100, $result['coverage']['excluded_directories'] );
		$this->assertSame( 'package-000/tests', $result['coverage']['excluded_directories'][0] );
		$this->assertSame( 'package-099/tests', $result['coverage']['excluded_directories'][99] );
		$this->assertTrue( $result['coverage']['excluded_directories_truncated'] );
	}

	public function test_parse_failures_produce_partial_coverage(): void {
		$this->write_file( 'valid.php', "<?php new \\WC_Order();\n" );
		$this->write_file( 'broken.php', "<?php function broken( {\n" );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 'partial', $result['state'] );
		$this->assertFalse( $result['coverage']['complete'] );
		$this->assertSame( 2, $result['coverage']['files_discovered'] );
		$this->assertSame( 1, $result['coverage']['files_scanned'] );
		$this->assertSame( 'broken.php', $result['coverage']['parse_failures'][0]['file'] );
	}

	public function test_all_parse_failures_produce_unavailable_coverage(): void {
		$this->write_file( 'broken-a.php', "<?php function broken_a( {\n" );
		$this->write_file( 'broken-b.php', "<?php function broken_b( {\n" );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 'unavailable', $result['state'] );
		$this->assertSame( 'all_php_files_unparseable', $result['reason_code'] );
		$this->assertSame( 2, $result['coverage']['files_discovered'] );
		$this->assertSame( 0, $result['coverage']['files_scanned'] );
		$this->assertSame( 2, $result['coverage']['parse_failure_count'] );
		$this->assertFalse( $result['coverage']['complete'] );
	}

	public function test_duplicate_usages_are_aggregated_with_an_occurrence_count(): void {
		$this->write_file( 'plugin.php', "<?php wc_get_order( 1 ); wc_get_order( 2 );\n" );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );

		$this->assertSame( 1, $result['summary']['usage_count'] );
		$this->assertSame( 2, $result['summary']['occurrence_count'] );
		$this->assertCount( 1, $result['usages'] );
		$this->assertSame( 2, $result['usages'][0]['count'] );
		$this->assertSame( 'plugin.php', $result['usages'][0]['file'] );
		$this->assertSame( 1, $result['usages'][0]['line'] );
	}

	public function test_aggregation_preserves_member_origin_and_first_witness(): void {
		$this->write_file( 'a-plugin.php', <<<'PHP'
<?php
\WC_Order::get_status();
\WC_Order::get_status();
\WC_Order::get_total();
PHP
		);
		$this->write_file( 'vendor/runtime.php', "<?php\n\\WC_Order::get_status();\n" );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );
		$usages = [];
		foreach ( $result['usages'] as $usage ) {
			$usages[ $usage['member'] . ':' . $usage['origin'] ] = $usage;
		}

		$this->assertSame( 3, $result['summary']['usage_count'] );
		$this->assertSame( 4, $result['summary']['occurrence_count'] );
		$this->assertSame( 2, $result['summary']['extension_usage_count'] );
		$this->assertSame( 1, $result['summary']['bundled_usage_count'] );
		$this->assertSame( 3, $result['summary']['extension_occurrence_count'] );
		$this->assertSame( 1, $result['summary']['bundled_occurrence_count'] );
		$this->assertSame( 2, $usages['get_status:extension']['count'] );
		$this->assertSame( 'a-plugin.php', $usages['get_status:extension']['file'] );
		$this->assertSame( 2, $usages['get_status:extension']['line'] );
		$this->assertSame( 1, $usages['get_total:extension']['count'] );
		$this->assertSame( 1, $usages['get_status:bundled']['count'] );
	}

	public function test_repeated_references_do_not_scale_the_observation_payload(): void {
		$this->write_file( 'plugin.php', "<?php\n" . str_repeat( "new \\WC_Order();\n", 2000 ) );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory );
		$json   = json_encode( $result );

		$this->assertSame( 1, $result['summary']['usage_count'] );
		$this->assertSame( 2000, $result['summary']['occurrence_count'] );
		$this->assertSame( 2000, $result['summary']['extension_occurrence_count'] );
		$this->assertSame( 2000, $result['usages'][0]['count'] );
		$this->assertSame( 2, $result['usages'][0]['line'] );
		$this->assertIsString( $json );
		$this->assertLessThan( 2500, strlen( $json ) );
	}

	public function test_missing_empty_and_invalid_roots_are_unavailable(): void {
		$scanner = new EcosystemUsageScanner();

		$missing = $scanner->scan( $this->temporary_directory . '/missing' );
		$this->assertSame( 'unavailable', $missing['state'] );
		$this->assertSame( 'sut_unavailable', $missing['reason_code'] );

		$empty = $scanner->scan( $this->temporary_directory );
		$this->assertSame( 'no_php_files', $empty['reason_code'] );
		$this->assertSame( 0, $empty['coverage']['excluded_directory_count'] );

		$this->write_file( 'tests/ignored.php', "<?php new \\WC_Order();\n" );
		$excluded_only = $scanner->scan( $this->temporary_directory );
		$this->assertSame( 'no_php_files', $excluded_only['reason_code'] );
		$this->assertSame( 1, $excluded_only['coverage']['excluded_directory_count'] );
		$this->assertSame( [ 'tests' ], $excluded_only['coverage']['excluded_directories'] );

		$this->write_file( 'plugin.php', "<?php new \\WC_Order();\n" );
		$invalid = $scanner->scan( $this->temporary_directory, 'not-a-version' );
		$this->assertSame( 'invalid_php_version', $invalid['reason_code'] );
		$this->assertSame( 1, $invalid['coverage']['files_discovered'] );
		$this->assertSame( 1, $invalid['coverage']['excluded_directory_count'] );
		$this->assertSame( [ 'tests' ], $invalid['coverage']['excluded_directories'] );
	}

	public function test_command_records_artifact_identity_and_merges_without_overwriting_other_results(): void {
		$this->write_file( 'plugin.php', "<?php wc_get_order( 123 );\n" );
		$artifact_path = $this->temporary_directory . '/plugin.zip';
		file_put_contents( $artifact_path, 'artifact bytes' );

		$compatibility_path = $this->temporary_directory . '/compatibility.json';
		file_put_contents( $compatibility_path, json_encode( [
			'state'        => 'observed',
			'summary'      => [ 'introduced_count' => 1 ],
			'observations' => [
				'internal_namespace_usage' => [ 'state' => 'observed' ],
			],
		] ) );
		chmod( $compatibility_path, 0644 );

		$command = new CommandTester( new EcosystemUsageScanCommand() );
		$this->assertSame( Command::SUCCESS, $command->execute( [
			'source-directory'    => $this->temporary_directory,
			'--php-version'       => '7.4',
			'--consumer-slug'     => 'woocommerce-gateway-stripe',
			'--consumer-woo-id'   => '18627',
			'--consumer-version'  => '10.5.3',
			'--artifact-ref-json' => '{"source":"github_release","tag":"10.5.3"}',
			'--artifact-path'     => $artifact_path,
			'--merge-into'        => $compatibility_path,
		] ) );

		$merged      = json_decode( (string) file_get_contents( $compatibility_path ), true );
		$observation = $merged['observations']['ecosystem_usage'];

		$this->assertSame( 1, $merged['summary']['introduced_count'] );
		$this->assertSame( 'observed', $merged['observations']['internal_namespace_usage']['state'] );
		$this->assertSame( 'woocommerce-gateway-stripe', $observation['metadata']['consumer']['slug'] );
		$this->assertSame( 18627, $observation['metadata']['consumer']['woo_id'] );
		$this->assertSame( '10.5.3', $observation['metadata']['consumer']['version'] );
		$this->assertSame( 'github_release', $observation['metadata']['artifact']['source'] );
		$this->assertSame( hash_file( 'sha256', $artifact_path ), $observation['metadata']['artifact']['sha256'] );
		clearstatcache( true, $compatibility_path );
		$this->assertSame( 0644, fileperms( $compatibility_path ) & 0777 );
	}

	public function test_command_rejects_invalid_artifact_json(): void {
		$this->write_file( 'plugin.php', "<?php wc_get_order( 123 );\n" );
		$command = new CommandTester( new EcosystemUsageScanCommand() );

		$this->assertSame( Command::FAILURE, $command->execute( [
			'source-directory'    => $this->temporary_directory,
			'--artifact-ref-json' => '[]',
		] ) );
		$this->assertStringContainsString( 'must decode to an object', $command->getDisplay() );
	}

	public function test_stripe_feed_interface_fixture_is_the_known_positive_canary(): void {
		$fixture = __DIR__ . '/fixtures/ecosystem-usage/stripe-feed-interface-known-positive.php';
		$this->write_file( 'stripe-feed-interface-known-positive.php', (string) file_get_contents( $fixture ) );

		$result = ( new EcosystemUsageScanner() )->scan( $this->temporary_directory, '7.4' );
		$usage   = $this->find_usage(
			$result['usages'],
			'Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface',
			'class_implement'
		);

		$this->assertSame( 'observed', $result['state'] );
		$this->assertSame( 'stripe-feed-interface-known-positive.php', $usage['file'] );
		$this->assertSame( 'WC_Stripe_Agentic_Commerce_Csv_Feed', $usage['consumer_symbol'] );
	}

	public function test_surface_normalization_matches_the_shared_contract_fixture(): void {
		$fixture = json_decode(
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/plugins/cd-manager/tests/fixtures/ecosystem-usage/surface-normalization.json' ),
			true
		);

		foreach ( $fixture as $case ) {
			$this->assertSame(
				$case['surface_key'],
				SurfaceNormalizer::surface_key( $case['surface'], $case['kind'] )
			);
			$this->assertSame(
				$case['member_key'],
				SurfaceNormalizer::member_key( $case['member'], $case['kind'] )
			);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $usages
	 * @return array<string,mixed>
	 */
	private function find_usage( array $usages, string $surface, string $usage_kind ): array {
		foreach ( $usages as $usage ) {
			if ( $usage['surface'] === $surface && $usage['usage_kind'] === $usage_kind ) {
				return $usage;
			}
		}

		$this->fail( sprintf( 'Usage not found: %s %s', $usage_kind, $surface ) );
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
