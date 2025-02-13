<?php

$env = getenv();

$required_vars = [
	'PLUGIN_ACTIVATION_STACK',
	'WORDPRESS_VERSION',
	'WOOCOMMERCE_VERSION',
	'TEST_TYPE'
];

$tag_tests = new Update_Test_Tags( $env['TEST_TYPE'] );

if ( $env['REMOVE_TAGS'] == true ) {
	$tag_tests->update( true );
	echo 'Successfully removed all tags!' . PHP_EOL;
} else {
	$tag_tests->update();
	echo 'Successfully tagged all tests!' . PHP_EOL;
}

class Update_Test_Tags {
	private array $tag_info = [];
	private array $activation_list = [];
	private string $test_root = '';

	/**
	 * @throws Exception
	 */
	public function __construct( string $dir_name ) {
		$env = getenv();

		if ( file_exists( __DIR__ . '/../plugin-test-skips.php' ) ) {
			$qit_wordpress_version   = $env['WORDPRESS_VERSION'];
			$qit_woocommerce_version = $env['WOOCOMMERCE_VERSION'];
			$qit_test_type           = $env['TEST_TYPE'];
			$this->tag_info          = require __DIR__ . '/../plugin-test-skips.php';
		} else {
			throw new Exception( 'Could not find ci/plugin-test-skips.php!' );
		}

		if ( ! is_array( $this->tag_info ) ) {
			throw new Exception( sprintf( 'Expected an array and got %s', gettype( $this->tag_info ) ) );
		}

		$this->set_test_root( $dir_name );

		$plugin_stack = json_decode( $env['PLUGIN_ACTIVATION_STACK'], true );

		$this->activation_list = array_map( function ( $plugin ) {
			return $plugin['slug'];
		}, $plugin_stack );

	}

	public function set_test_root( string $dir_name ) {
		$this->test_root = __DIR__ . '/../' . $dir_name;

		echo 'Test root: ' . $this->test_root . PHP_EOL;
	}

	/**
	 * Adds or Removes tags based on the data in plugin-test-skips.php
	 *
	 * @param bool $remove Whether to add/remove tags
	 *
	 * @throws Exception
	 */
	public function update( bool $remove = false ): void {
		$tags = array_keys( $this->tag_info );

		foreach ( $tags as $tag ) {
			$plugins = $this->tag_info[ $tag ];
			foreach ( $plugins as $plugin => $files ) {
				$files_paths = array_keys( $files );

				foreach ( $files as $file => $tests ) {
					$file_path = $this->test_root . '/' . $file;

					if ( ! in_array( $plugin, $this->activation_list ) && $plugin !== 'all' ) {
						continue 2;
					}

					if ( ! file_exists( $file_path ) ) {
						echo "$file_path does not exist!" . PHP_EOL;
						continue 2;
					}

					$test_contents = file_get_contents( $file_path );

					if (
						$remove &&
						strpos( $test_contents, ' @' . $tag ) === false
					) {
						continue 3;
					}

					foreach ( $tests as $test ) {

						if ( strpos( $test_contents, $test ) === false ) {
							throw new Exception( sprintf( '%s could not be found in %s.', $test, $file ) );
						}

						if ( $remove ) {
							$test_contents = str_replace( ' @' . $tag, '', $test_contents );
						} else {
							$test_with_tag = $test . ' @' . $tag;
							$pattern       = "/(['\"]\s*)$test(\s*['\"])(,)/i";
							$test_contents = preg_replace( $pattern, "$1$test_with_tag$2$3", $test_contents, 1 );
						}
					}

					file_put_contents( $file_path, $test_contents );

					if ( $remove ) {
						echo sprintf( 'Removed @%s from %s ' . PHP_EOL, $tag, $file_path );
					} else {
						echo sprintf( 'Tagged %s with @%s ' . PHP_EOL, $file_path, $tag );
					}

				}
			}
		}
	}
}
