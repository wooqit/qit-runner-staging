<?php

use CI_CLI\FileHeaderMatcher;

class FileHeaderMatcherTest extends \PHPUnit\Framework\TestCase {
	/**
	 * @dataProvider valid_plugin_header_provider
	 */
	public function test_recognizes_plugin_headers_wordpress_would_load( string $contents ): void {
		$this->assertTrue( FileHeaderMatcher::contains_plugin_header( $contents ) );
	}

	/** @return array<string,array{string}> */
	public function valid_plugin_header_provider(): array {
		return [
			'docblock'             => [ "<?php\n/**\n * Plugin Name: My Plugin\n */\n" ],
			'line comment'         => [ "<?php\n// Plugin Name: My Plugin\n" ],
			'hash comment'         => [ "<?php\n# Plugin Name: My Plugin\n" ],
			'single-line block'    => [ "<?php /* Plugin Name: My Plugin */\n" ],
			'no space after colon' => [ "<?php\n/*\nPlugin Name:My Plugin\n*/\n" ],
			'CR-only line endings' => [ "<?php\r/**\r * Plugin Name: My Plugin\r */\r" ],
		];
	}

	/**
	 * @dataProvider invalid_plugin_header_provider
	 */
	public function test_rejects_plugin_headers_wordpress_would_not_load( string $contents ): void {
		$this->assertFalse( FileHeaderMatcher::contains_plugin_header( $contents ) );
	}

	/** @return array<string,array{string}> */
	public function invalid_plugin_header_provider(): array {
		return [
			'whitespace before colon' => [ "<?php\n/* Plugin Name : My Plugin */\n" ],
			'newline before colon'    => [ "<?php\n/*\nPlugin Name\n: My Plugin\n*/\n" ],
			'empty value'             => [ "<?php\n/* Plugin Name:\n */\n" ],
			'comment terminator only' => [ "<?php\n/* Plugin Name: */\n" ],
			'incidental prose'        => [ "<?php\n// This helper mentions Plugin Name: in the middle of a sentence.\n" ],
		];
	}

	/**
	 * @dataProvider invalid_theme_header_provider
	 */
	public function test_rejects_theme_headers_wordpress_would_not_load( string $contents ): void {
		$this->assertFalse( FileHeaderMatcher::contains_theme_header( $contents ) );
	}

	/** @return array<string,array{string}> */
	public function invalid_theme_header_provider(): array {
		return [
			'whitespace before colon' => [ "/*\nTheme Name : My Theme\n*/\n" ],
			'newline before colon'    => [ "/*\nTheme Name\n: My Theme\n*/\n" ],
			'empty value'             => [ "/*\nTheme Name:\n*/\n" ],
			'comment terminator only' => [ "/* Theme Name: */\n" ],
		];
	}
}
