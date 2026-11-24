<?php

class PhpstanConfigGeneratorTest extends \PHPUnit\Framework\TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/qit-phpstan-generator-' . uniqid( '', true );
		mkdir( $this->directory . '/ci/plugins/mailpoet/vendor', 0777, true );
		mkdir( $this->directory . '/ci/tmp/wordpress', 0777, true );
	}

	protected function tearDown(): void {
		$this->delete_directory( $this->directory );
	}

	public function test_regression_config_sets_php_version_and_safely_loads_mailpoet_autoloader(): void {
		$marker_path   = $this->directory . '/autoload-loaded';
		$autoload_path = $this->directory . '/ci/plugins/mailpoet/vendor/autoload.php';
		file_put_contents( $autoload_path, sprintf(
			"<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit( 0 );\n}\nfile_put_contents( %s, 'loaded' );\n",
			var_export( $marker_path, true )
		) );

		$config_path    = $this->directory . '/phpstan.neon';
		$bootstrap_path = $this->directory . '/bootstrap.php';
		$generator      = dirname( __DIR__, 2 ) . '/tests/phpstan/generate-phpstan.php';
		$command        = sprintf(
			'GITHUB_WORKSPACE=%s SUT_TYPE=plugin PHPSTAN_LEVEL=0 PHP_VERSION=7.4 PHPSTAN_REGRESSION_MODE=1 PHPSTAN_CONFIG_OUTPUT=%s PHPSTAN_BOOTSTRAP_OUTPUT=%s %s %s mailpoet',
			escapeshellarg( $this->directory ),
			escapeshellarg( $config_path ),
			escapeshellarg( $bootstrap_path ),
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $generator )
		);

		exec( $command, $output, $exit_code );
		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
		$config_contents = (string) file_get_contents( $config_path );
		$this->assertStringContainsString( 'phpVersion: 70400', $config_contents );
		$this->assertStringContainsString( $bootstrap_path, $config_contents );
		$this->assertStringContainsString( "define( 'ABSPATH'", (string) file_get_contents( $bootstrap_path ) );
		$this->assertStringNotContainsString(
			'identifier: return.missing',
			$config_contents,
			'PHPStan marks return.missing as non-ignorable; ignoring it creates a top-level error instead of a comparable file finding.'
		);

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $bootstrap_path ), $bootstrap_output, $bootstrap_exit );
		$this->assertSame( 0, $bootstrap_exit, implode( "\n", $bootstrap_output ) );
		$this->assertSame( 'loaded', file_get_contents( $marker_path ) );
	}

	public function test_woo_stubs_off_leaves_the_config_unchanged(): void {
		$this->make_woo_stubs( [ 'woocommerce-subscriptions' ] );

		$unset = $this->generate( 'mailpoet', [] );
		$off   = $this->generate( 'mailpoet', [ 'PHPSTAN_WOO_STUBS' => 'false' ] );

		$this->assertSame( $unset, $off );
		$this->assertStringNotContainsString( 'php-stubs/stubs', $unset );
	}

	public function test_woo_stubs_include_extensions_outside_the_plugin_stack_only(): void {
		mkdir( $this->directory . '/ci/plugins/woocommerce-bookings' );
		file_put_contents(
			$this->directory . '/ci/plugins/mailpoet/phpstan.neon.dist',
			"includes:\n    - vendor/woocommerce/php-stubs/stubs/woocommerce-product-bundles/8.0.0.neon\n"
		);
		$this->make_woo_stubs( [
			'mailpoet',
			'woocommerce-bookings',
			'woocommerce-product-bundles',
			'woocommerce-subscriptions',
			'woocommerce-deposits',
		] );
		// A shared framework has no .neon of its own; extensions list it in their scanFiles.
		mkdir( $this->woo_stubs_dir() . '/skyverge-wc-plugin-framework', 0777, true );

		$config = $this->generate( 'mailpoet', [ 'PHPSTAN_WOO_STUBS' => 'true' ], $output );

		$stubs = $this->woo_stubs_dir();
		$this->assertStringContainsString( "- $stubs/woocommerce-deposits/latest.neon\n  - $stubs/woocommerce-subscriptions/latest.neon", $config );
		$this->assertStringNotContainsString( "$stubs/mailpoet/", $config, 'The SUT is analysed from its real code.' );
		$this->assertStringNotContainsString( "$stubs/woocommerce-bookings/", $config, 'A plugin in the stack keeps its stubz stubs.' );
		$this->assertStringNotContainsString( "$stubs/woocommerce-product-bundles/", $config, "The SUT config's own pin wins." );
		$this->assertStringNotContainsString( 'skyverge-wc-plugin-framework', $config );
		$this->assertStringContainsString( 'skipping woocommerce-product-bundles (included by the SUT config)', $output );
		$this->assertStringContainsString( 'skipping woocommerce-bookings (in the plugin stack)', $output );
	}

	public function test_woo_stubs_follow_the_sut_config_includes_to_find_its_pins(): void {
		$sut = $this->directory . '/ci/plugins/mailpoet';
		mkdir( "$sut/config" );
		file_put_contents( "$sut/phpstan.neon.dist", "includes:\n    - config/phpstan-stubs.neon\n" );
		file_put_contents(
			"$sut/config/phpstan-stubs.neon",
			"includes:\n    - '../vendor/woocommerce/php-stubs/stubs/woocommerce-subscriptions/7.0.0.neon'\n" .
			"    - ../config/phpstan-stubs.neon\n" .
			"parameters:\n    scanDirectories:\n        - ../vendor/woocommerce/php-stubs/stubs/woocommerce-memberships\n"
		);
		$this->make_woo_stubs( [ 'woocommerce-subscriptions', 'woocommerce-memberships', 'woocommerce-deposits' ] );

		$config = $this->generate( 'mailpoet', [ 'PHPSTAN_WOO_STUBS' => 'true' ], $output );

		$stubs = $this->woo_stubs_dir();
		$this->assertStringContainsString( "$stubs/woocommerce-deposits/latest.neon", $config );
		$this->assertStringNotContainsString( "$stubs/woocommerce-subscriptions/", $config, 'A pin in an included file wins.' );
		$this->assertStringNotContainsString( "$stubs/woocommerce-memberships/", $config, 'A pin with no trailing slash wins.' );
		$this->assertStringContainsString( 'skipping woocommerce-memberships (included by the SUT config)', $output );
	}

	public function test_woo_stubs_for_a_theme_skip_the_plugins_in_the_stack(): void {
		mkdir( $this->directory . '/ci/themes/storefront', 0777, true );
		mkdir( $this->directory . '/ci/plugins/woocommerce-subscriptions' );
		$this->make_woo_stubs( [ 'woocommerce-subscriptions', 'woocommerce-deposits' ] );

		$config = $this->generate( 'storefront', [ 'SUT_TYPE' => 'theme', 'PHPSTAN_WOO_STUBS' => 'true' ], $output );

		$stubs = $this->woo_stubs_dir();
		$this->assertStringContainsString( "$stubs/woocommerce-deposits/latest.neon", $config );
		$this->assertStringNotContainsString( "$stubs/woocommerce-subscriptions/", $config );
		$this->assertStringContainsString( 'skipping woocommerce-subscriptions (in the plugin stack)', $output );
	}

	public function test_woo_stubs_skip_extensions_that_declare_the_sut_s_own_classes(): void {
		$sut = $this->directory . '/ci/plugins/woocommerce-subscriptions-gifting';
		mkdir( "$sut/vendor/lib", 0777, true );
		// Subscriptions 9.x includes Gifting, so its stubs declare the standalone plugin's main class.
		file_put_contents( "$sut/woocommerce-subscriptions-gifting.php", "<?php\nclass WCS_Gifting {\n\tpublic static \$plugin_file = __FILE__;\n}\n" );
		// Same short name as a Bookings stub class, but another namespace: not a clash.
		file_put_contents( "$sut/helper.php", "<?php\nnamespace Gifting\\Admin;\n\nfinal class Helper {}\n" );
		// Bundled code isn't checked, so a Deposits stub declaring this class is still included.
		file_put_contents( "$sut/vendor/lib/deposits.php", "<?php\nclass WC_Deposits {}\n" );

		$this->make_woo_stubs( [ 'woocommerce-subscriptions', 'woocommerce-bookings', 'woocommerce-deposits' ] );
		$this->write_stub_classes( 'woocommerce-subscriptions', "namespace {\n    class WC_Subscription {}\n    class WCS_Gifting {}\n}\n" );
		$this->write_stub_classes( 'woocommerce-bookings', "namespace Bookings\\Admin {\n    final class Helper {}\n}\n" );
		$this->write_stub_classes( 'woocommerce-deposits', "namespace {\n    class WC_Deposits {}\n}\n" );

		$config = $this->generate( 'woocommerce-subscriptions-gifting', [ 'PHPSTAN_WOO_STUBS' => 'true' ], $output );

		$stubs = $this->woo_stubs_dir();
		$this->assertStringNotContainsString( "$stubs/woocommerce-subscriptions/", $config );
		$this->assertStringContainsString( "$stubs/woocommerce-bookings/latest.neon", $config );
		$this->assertStringContainsString( "$stubs/woocommerce-deposits/latest.neon", $config );
		$this->assertStringContainsString( "skipping woocommerce-subscriptions (declares 1 of the SUT's classes, e.g. wcs_gifting)", $output );
	}

	public function test_woo_stubs_are_never_used_in_regression_mode(): void {
		$this->make_woo_stubs( [ 'woocommerce-subscriptions' ] );

		$regression = $this->generate( 'mailpoet', [ 'PHPSTAN_REGRESSION_MODE' => '1' ] );
		$with_stubs = $this->generate( 'mailpoet', [ 'PHPSTAN_REGRESSION_MODE' => '1', 'PHPSTAN_WOO_STUBS' => 'true' ] );

		$this->assertSame( $regression, $with_stubs );
		$this->assertStringNotContainsString( 'php-stubs/stubs', $with_stubs );
	}

	public function test_woo_stubs_are_never_used_when_the_sut_is_woocommerce(): void {
		mkdir( $this->directory . '/ci/plugins/woocommerce' );
		$this->make_woo_stubs( [ 'woocommerce-subscriptions' ] );

		$off        = $this->generate( 'woocommerce', [] );
		$with_stubs = $this->generate( 'woocommerce', [ 'PHPSTAN_WOO_STUBS' => 'true' ], $output );

		$this->assertSame( $off, $with_stubs );
		$this->assertStringNotContainsString( 'php-stubs/stubs', $with_stubs );
		$this->assertStringContainsString( 'woocommerce/php-stubs: off, the SUT is WooCommerce.', $output );
	}

	public function test_woo_stubs_fail_loudly_when_the_package_is_missing(): void {
		exec( $this->generator_command( 'mailpoet', [ 'PHPSTAN_WOO_STUBS' => 'true' ] ), $output, $exit_code );

		$this->assertSame( 104, $exit_code );
		$this->assertStringContainsString( 'woocommerce/php-stubs is not installed', implode( "\n", $output ) );
	}

	private function woo_stubs_dir(): string {
		return $this->directory . '/ci/tests/phpstan/vendor/woocommerce/php-stubs/stubs';
	}

	/**
	 * @param array<string> $slugs
	 */
	private function make_woo_stubs( array $slugs ): void {
		foreach ( $slugs as $slug ) {
			mkdir( $this->woo_stubs_dir() . "/$slug", 0777, true );
			file_put_contents( $this->woo_stubs_dir() . "/$slug/latest.neon", "includes:\n    - 1.0.0.neon\n" );
		}
	}

	/**
	 * Gives an extension's stubs a real version file with these declarations, the way
	 * php-stubs lays them out: latest.neon includes 1.0.0.neon, which scans 1.0.0.php.
	 */
	private function write_stub_classes( string $slug, string $declarations ): void {
		$dir = $this->woo_stubs_dir() . "/$slug";
		file_put_contents( "$dir/1.0.0.neon", "parameters:\n    scanFiles:\n        - 1.0.0.php\n" );
		file_put_contents( "$dir/1.0.0.php", "<?php\n\n$declarations" );
	}

	/**
	 * @param array<string,string> $env
	 */
	private function generate( string $sut_slug, array $env, ?string &$output = null ): string {
		$config_path = $this->directory . '/phpstan.neon';
		exec( $this->generator_command( $sut_slug, $env + [ 'PHPSTAN_CONFIG_OUTPUT' => $config_path ] ), $lines, $exit_code );
		$output = implode( "\n", $lines );
		$this->assertSame( 0, $exit_code, $output );

		return (string) file_get_contents( $config_path );
	}

	/**
	 * @param array<string,string> $env
	 */
	private function generator_command( string $sut_slug, array $env ): string {
		$env += [
			'GITHUB_WORKSPACE'      => $this->directory,
			'SUT_TYPE'              => 'plugin',
			'PHPSTAN_LEVEL'         => '2',
			'PHP_VERSION'           => '8.2',
			'PHPSTAN_CONFIG_OUTPUT' => $this->directory . '/phpstan.neon',
		];
		// exec() inherits this process's environment, so a variable left out of $env would
		// otherwise come from the developer's or CI shell instead of being unset.
		$assignments = 'env ';
		foreach ( [ 'PHPSTAN_WOO_STUBS', 'PHPSTAN_REGRESSION_MODE', 'PHPSTAN_BOOTSTRAP_OUTPUT' ] as $name ) {
			if ( ! isset( $env[ $name ] ) ) {
				$assignments .= '-u ' . $name . ' ';
			}
		}
		foreach ( $env as $name => $value ) {
			$assignments .= $name . '=' . escapeshellarg( $value ) . ' ';
		}

		return sprintf(
			'%s%s %s %s 2>&1',
			$assignments,
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( dirname( __DIR__, 2 ) . '/tests/phpstan/generate-phpstan.php' ),
			escapeshellarg( $sut_slug )
		);
	}

	private function delete_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
