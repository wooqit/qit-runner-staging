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
