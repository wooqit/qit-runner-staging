<?php

require_once __DIR__ . '/../../tests/validation/parent-item-key-scanner.php';

class ParentItemKeyScannerTest extends \PHPUnit\Framework\TestCase {
	private const FIXTURES = __DIR__ . '/fixtures/parent-item-key';

	private string $temporary_directory;

	/** @var string[] */
	private array $printed_lines = [];

	protected function setUp(): void {
		parent::setUp();
		$this->temporary_directory = sys_get_temp_dir() . '/qit-parent-item-key-' . uniqid( '', true );
		mkdir( $this->temporary_directory, 0777, true );
	}

	protected function tearDown(): void {
		$this->delete_directory( $this->temporary_directory );
		parent::tearDown();
	}

	public function test_reports_cart_line_keys_stored_in_cart_item_data(): void {
		$result = $this->scan( self::FIXTURES . '/child-items-plugin' );

		$this->assertSame( [
			'uses_filter' => false,
			'matches'     => [
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 12 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 16 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 18 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 20 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 22 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 24 ],
				[ 'file' => 'includes/class-child-items-cart.php', 'line' => 28 ],
			],
			'truncated'   => false,
		], $result );
		$this->assertSame( [], $this->printed_lines );
	}

	public function test_the_filter_name_as_an_add_filter_argument_is_detected(): void {
		$result = $this->scan( self::FIXTURES . '/filter-plugin' );

		$this->assertTrue( $result['uses_filter'] );
		$this->assertSame( [ [ 'file' => 'filter-plugin.php', 'line' => 9 ] ], $result['matches'] );
	}

	public function test_the_filter_name_as_a_constant_value_is_detected(): void {
		$result = $this->scan( self::FIXTURES . '/filter-constant-plugin' );

		$this->assertTrue( $result['uses_filter'] );
		$this->assertSame( [ [ 'file' => 'filter-constant-plugin.php', 'line' => 9 ] ], $result['matches'] );
	}

	public function test_only_a_double_slash_comment_marker_on_the_value_line_dismisses_a_match(): void {
		$result = $this->scan( self::FIXTURES . '/markers-plugin' );

		$reported_lines = array_column( $result['matches'], 'line' );

		// 7 `//` and 13 (marker on the value's line, key on the line above) are dismissed.
		$this->assertSame( [
			9, // `#` comment
			16, // phpcs:ignore
			18, // phpcs:ignore with a sniff list
			20, // marker inside a string
			22, // block comment
			24, // docblock
			27, // marker on the line above
			29, // marker on the line below
			33, // marker on the array's opening line, not the value's
		], $reported_lines );
	}

	public function test_a_marker_after_another_comment_on_the_value_line_dismisses_a_match(): void {
		$this->write_file( 'plugin/cart.php', implode( "\n", [
			'<?php',
			"\$a = [ 'bundled_by' => \$bundle_cart_key ]; // phpcs:ignore WordPress.Arrays -- qit-ignore: parent-item-key",
			"\$b = [ 'bundled_by' => \$bundle_cart_key ]; // phpcs:ignore WordPress.Arrays // qit-ignore: parent-item-key",
			"\$c = [ 'bundled_by' => \$bundle_cart_key ]; // qit-ignore: parent-item-keys",
			"\$d = [ 'bundled_by' => \$bundle_cart_key ]; // no-qit-ignore: parent-item-key",
			"\$e = [ 'bundled_by' => \$bundle_cart_key ]; # qit-ignore: parent-item-key",
			"\$f = [ 'bundled_by' => \$bundle_cart_key ]; # phpcs:ignore // qit-ignore: parent-item-key",
			'',
		] ) );

		$this->assertSame( [ 4, 5, 6, 7 ], array_column( $this->scan( $this->temporary_directory . '/plugin' )['matches'], 'line' ) );
	}

	public function test_a_string_that_only_contains_the_filter_name_is_not_the_filter(): void {
		$this->write_file( 'plugin/cart.php', "<?php\n\$notice = 'Hook woocommerce_store_api_cart_item_parent_item_key to declare children';\n\$hook = 'woocommerce_store_api_cart_item_parent_item_key_legacy';\n" );

		$this->assertFalse( $this->scan( $this->temporary_directory . '/plugin' )['uses_filter'] );
	}

	public function test_keys_after_other_elements_and_container_key_variables_are_reported(): void {
		$this->write_file( 'plugin/cart.php', implode( "\n", [
			'<?php',
			"\$a = array( 'quantity' => 1, 'bundled_by' => \$bundle_cart_key );",
			"\$b = [ 'composite_parent' => \$container_key ];",
			"\$c['mnm_container'] = \$Parent_Container_Key;",
			'',
		] ) );

		$this->assertSame( [ 2, 3, 4 ], array_column( $this->scan( $this->temporary_directory . '/plugin' )['matches'], 'line' ) );
	}

	public function test_constant_keys_are_reported_whatever_the_constant_is_named(): void {
		$this->write_file( 'plugin/cart.php', implode( "\n", [
			'<?php',
			'$a = [ PARENT_ITEM_KEY => $parent_item_key ];',
			'$b[ PARENT_ITEM_KEY ] = $parent_item_key;',
			'$c = [ self::KEY => $parent_item_key ];',
			'',
		] ) );

		$this->assertSame( [ 2, 3, 4 ], array_column( $this->scan( $this->temporary_directory . '/plugin' )['matches'], 'line' ) );
	}

	public function test_an_index_assignment_inside_an_array_is_reported(): void {
		$this->write_file( 'plugin/cart.php', "<?php\n\$args = [ \$cart_item['bundled_by'] = \$bundle_cart_key ];\n" );

		$this->assertSame( [ 2 ], array_column( $this->scan( $this->temporary_directory . '/plugin' )['matches'], 'line' ) );
	}

	public function test_computed_keys_and_expression_values_are_not_reported(): void {
		$this->write_file( 'plugin/cart.php', implode( "\n", [
			'<?php',
			"\$a = [ \$prefix . 'bundled_by' => \$bundle_cart_key ];",
			"\$b[ \$prefix . 'bundled_by' ] = \$bundle_cart_key;",
			"\$c['bundled_by'] = \$bundle_cart_key . '_child';",
			"\$d = [ 'bundled_by' => \$bundle_cart_key . '_child' ];",
			"\$e = [ 'bundled_by' => \$this->bundle_cart_key ];",
			'',
		] ) );

		$this->assertSame( [], $this->scan( $this->temporary_directory . '/plugin' )['matches'] );
	}

	public function test_two_matches_on_one_line_are_reported_once(): void {
		$this->write_file( 'plugin/cart.php', "<?php\n\$a = [ 'bundled_by' => \$bundle_cart_key, 'bundle_sell_of' => \$parent_item_key ];\n" );

		$this->assertSame( [ [ 'file' => 'cart.php', 'line' => 2 ] ], $this->scan( $this->temporary_directory . '/plugin' )['matches'] );
	}

	public function test_a_path_that_is_not_utf8_is_skipped_so_the_results_still_encode(): void {
		$this->write_file( 'plugin/ok.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( "plugin/caf\xe9/cart.php", "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		$result = $this->scan( $this->temporary_directory . '/plugin' );

		$this->assertSame( [ [ 'file' => 'ok.php', 'line' => 2 ] ], $result['matches'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertNotFalse( json_encode( $result ) );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertNotFalse( json_encode( $this->printed_lines ) );
	}

	public function test_a_file_that_is_not_php_is_ignored_whatever_its_name(): void {
		$this->write_file( 'plugin/ok.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( "plugin/caf\xe9.png", '' );

		$result = $this->scan( $this->temporary_directory . '/plugin' );

		$this->assertSame( [ [ 'file' => 'ok.php', 'line' => 2 ] ], $result['matches'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( [], $this->printed_lines );
	}

	public function test_default_limits_scan_a_php_file_of_up_to_one_megabyte(): void {
		$contents = "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n";
		$this->write_file( 'plugin/large.php', $contents . str_repeat( '/' . '/ padding' . "\n", intdiv( 1048576 - strlen( $contents ), 11 ) ) );

		$result = $this->scan( $this->temporary_directory . '/plugin' );

		$this->assertSame( [ [ 'file' => 'large.php', 'line' => 2 ] ], $result['matches'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_traversal_stops_after_the_directory_limit(): void {
		$this->write_file( 'plugin/a/a.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( 'plugin/b/empty/.keep', '' );
		$this->write_file( 'plugin/c/c.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 5000, 524288, 50, 3 ) );

		$this->assertSame( [ 'a/a.php' ], array_column( $result['matches'], 'file' ) );
		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertStringContainsString( '3', $this->printed_lines[0] );
	}

	public function test_reaching_the_directory_limit_exactly_is_a_complete_scan(): void {
		$this->write_file( 'plugin/a/a.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( 'plugin/b/b.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 5000, 524288, 50, 3 ) );

		$this->assertCount( 2, $result['matches'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( [], $this->printed_lines );
	}

	public function test_vendor_and_node_modules_directories_are_skipped_at_any_depth(): void {
		$match = "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\nadd_filter( 'woocommerce_store_api_cart_item_parent_item_key', 'f' );\n";
		$this->write_file( 'plugin/plugin.php', "<?php\n// Plugin Name: Plugin\n" );
		$this->write_file( 'plugin/vendor/package/cart.php', $match );
		$this->write_file( 'plugin/node_modules/package/cart.php', $match );
		$this->write_file( 'plugin/lib/vendor/cart.php', $match );
		$this->write_file( 'plugin/lib/node_modules/cart.php', $match );

		$this->assertSame(
			[ 'uses_filter' => false, 'matches' => [], 'truncated' => false ],
			$this->scan( $this->temporary_directory . '/plugin' )
		);
	}

	public function test_symlinks_are_not_followed(): void {
		$match = "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n";
		$this->write_file( 'outside/cart.php', $match );
		$this->write_file( 'outside/dir/cart.php', $match );
		$this->write_file( 'plugin/plugin.php', "<?php\n// Plugin Name: Plugin\n" );
		symlink( $this->temporary_directory . '/outside/cart.php', $this->temporary_directory . '/plugin/linked-cart.php' );
		symlink( $this->temporary_directory . '/outside/dir', $this->temporary_directory . '/plugin/linked-dir' );

		$this->assertSame( [], $this->scan( $this->temporary_directory . '/plugin' )['matches'] );
	}

	public function test_only_php_files_are_scanned(): void {
		$this->write_file( 'plugin/plugin.php', "<?php\n// Plugin Name: Plugin\n" );
		$this->write_file( 'plugin/cart.inc', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		$this->assertSame( [], $this->scan( $this->temporary_directory . '/plugin' )['matches'] );
	}

	public function test_scanning_stops_after_the_file_limit(): void {
		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			$this->write_file( "plugin/{$name}.php", "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		}

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 2 ) );

		$this->assertSame( [ 'a.php', 'b.php' ], array_column( $result['matches'], 'file' ) );
		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertStringContainsString( '2', $this->printed_lines[0] );
	}

	public function test_reaching_the_file_limit_exactly_is_a_complete_scan(): void {
		foreach ( [ 'a', 'b' ] as $name ) {
			$this->write_file( "plugin/{$name}.php", "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		}

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 2 ) );

		$this->assertCount( 2, $result['matches'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( [], $this->printed_lines );
	}

	public function test_files_over_the_size_limit_are_skipped(): void {
		$this->write_file( 'plugin/small.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( 'plugin/large.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" . str_repeat( "// padding\n", 20 ) );

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 5000, 100 ) );

		$this->assertSame( [ [ 'file' => 'small.php', 'line' => 2 ] ], $result['matches'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertStringContainsString( 'large.php', $this->printed_lines[0] );
	}

	public function test_unreadable_files_are_skipped(): void {
		$this->write_file( 'plugin/readable.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( 'plugin/unreadable.php', "<?php\n\$data = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		// chmod cannot make a file unreadable for root, so the read itself fails here.
		$scanner = new class() extends QIT_Parent_Item_Key_Scanner {
			protected function read_file( $path ) {
				return basename( $path ) === 'unreadable.php' ? false : parent::read_file( $path );
			}
		};

		$result = $this->scan( $this->temporary_directory . '/plugin', $scanner );

		$this->assertSame( [ [ 'file' => 'readable.php', 'line' => 2 ] ], $result['matches'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertStringContainsString( 'unreadable.php', $this->printed_lines[0] );
	}

	public function test_matches_beyond_the_limit_are_not_reported_but_the_filter_is_still_found(): void {
		$this->write_file( 'plugin/a.php', "<?php\n\$a = [ 'bundled_by' => \$bundle_cart_key ];\n\$b = [ 'bundled_by' => \$bundle_cart_key ];\n\$c = [ 'bundled_by' => \$bundle_cart_key ];\n" );
		$this->write_file( 'plugin/z.php', "<?php\nadd_filter( 'woocommerce_store_api_cart_item_parent_item_key', 'f' );\n" );

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 5000, 2 * 1024 * 1024, 2 ) );

		$this->assertSame( [ [ 'file' => 'a.php', 'line' => 2 ], [ 'file' => 'a.php', 'line' => 3 ] ], $result['matches'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertTrue( $result['uses_filter'] );
		$this->assertCount( 1, $this->printed_lines );
	}

	public function test_reaching_the_match_limit_exactly_is_a_complete_scan(): void {
		$this->write_file( 'plugin/a.php', "<?php\n\$a = [ 'bundled_by' => \$bundle_cart_key ];\n\$b = [ 'bundled_by' => \$bundle_cart_key ];\n" );

		$result = $this->scan( $this->temporary_directory . '/plugin', new QIT_Parent_Item_Key_Scanner( 5000, 2 * 1024 * 1024, 2 ) );

		$this->assertCount( 2, $result['matches'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_a_scan_that_throws_omits_the_result_and_prints_a_line(): void {
		$result = $this->scan( $this->temporary_directory . '/missing-plugin' );

		$this->assertNull( $result );
		$this->assertCount( 1, $this->printed_lines );
	}

	public function test_a_missing_tokenizer_omits_the_result_and_prints_a_line(): void {
		$scanner = new class() extends QIT_Parent_Item_Key_Scanner {
			protected function tokenizer_available(): bool {
				return false;
			}
		};

		$this->assertNull( $this->scan( self::FIXTURES . '/child-items-plugin', $scanner ) );
		$this->assertCount( 1, $this->printed_lines );
		$this->assertStringContainsString( 'tokenizer', $this->printed_lines[0] );
	}

	private function scan( string $plugin_root, ?QIT_Parent_Item_Key_Scanner $scanner = null ): ?array {
		$scanner = $scanner ?? new QIT_Parent_Item_Key_Scanner();

		return $scanner->scan( $plugin_root, function ( string $line ): void {
			$this->printed_lines[] = $line;
		} );
	}

	private function write_file( string $relative_path, string $contents ): void {
		$path = $this->temporary_directory . '/' . $relative_path;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $contents );
	}

	private function delete_directory( string $directory ): void {
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$path = $directory . '/' . $entry;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				unlink( $path );
			} else {
				$this->delete_directory( $path );
			}
		}

		rmdir( $directory );
	}
}
