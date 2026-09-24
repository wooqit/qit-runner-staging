<?php

use CI_CLI\PluginDownloadException;
use CI_CLI\ZipHandler;
use Symfony\Component\Console\Output\BufferedOutput;

class ZipHandlerTest extends \PHPUnit\Framework\TestCase {
	/** @var array<string> */
	private array $temp_paths = [];

	protected function tearDown(): void {
		foreach ( $this->temp_paths as $path ) {
			$this->remove_path( $path );
		}
		$this->temp_paths = [];
		parent::tearDown();
	}

	private function remove_path( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( $entry !== '.' && $entry !== '..' ) {
					$this->remove_path( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/** @param array<string,string> $entries Zip entry name => file contents. */
	private function create_zip( array $entries ): string {
		$zip_path = sys_get_temp_dir() . '/qit-zip-handler-' . uniqid( '', true ) . '.zip';
		$zip      = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, \ZipArchive::CREATE ) );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		$this->temp_paths[] = $zip_path;

		return $zip_path;
	}

	private function create_extract_dir(): string {
		$dir = sys_get_temp_dir() . '/qit-extract-' . uniqid( '', true );
		mkdir( $dir );
		$this->temp_paths[] = $dir;

		return $dir;
	}

	private function extract( string $zip_path, string $slug, string $type, string $extract_dir ): string {
		$output  = new BufferedOutput();
		$handler = new ZipHandler( $zip_path, $slug, $type, $extract_dir, $output );
		$handler->extract();

		return $output->fetch();
	}

	private function valid_plugin_header( string $name ): string {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Version: 1.0.0\n */\n";
	}

	/**
	 * A file that contains the words "Plugin Name" in prose, but no real plugin header.
	 * The old substring-based detection misclassified files like this as entry points.
	 */
	private function incidental_plugin_name_text( string $class_name ): string {
		return "<?php\n/**\n * The admin-specific functionality. Uses the Plugin Name and version to enqueue assets.\n */\nclass {$class_name} {}\n";
	}

	public function test_flat_archive_with_incidental_plugin_name_text_extracts_into_slug_directory(): void {
		$zip = $this->create_zip( [
			'coupon-referral-program.php'                      => $this->valid_plugin_header( 'Coupon Referral Program' ),
			'admin/class-coupon-referral-program-admin.php'    => $this->incidental_plugin_name_text( 'Coupon_Referral_Program_Admin' ),
			'includes/class-coupon-referral-program.php'       => $this->incidental_plugin_name_text( 'Coupon_Referral_Program' ),
			'public/class-coupon-referral-program-public.php'  => $this->incidental_plugin_name_text( 'Coupon_Referral_Program_Public' ),
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'coupon-referral-program', 'plugin', $dir );

		$this->assertFileExists( $dir . '/coupon-referral-program/coupon-referral-program.php' );
		$this->assertFileExists( $dir . '/coupon-referral-program/public/class-coupon-referral-program-public.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/public' );
		$this->assertDirectoryDoesNotExist( $dir . '/admin' );
	}

	public function test_flat_archive_prefers_root_bootstrap_over_subdirectories_with_real_headers(): void {
		$zip = $this->create_zip( [
			'my-plugin.php'            => $this->valid_plugin_header( 'My Plugin' ),
			'admin/class-admin.php'    => $this->valid_plugin_header( 'My Plugin' ),
			'public/class-public.php'  => $this->valid_plugin_header( 'My Plugin' ),
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertFileExists( $dir . '/my-plugin/admin/class-admin.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/admin' );
	}

	public function test_flat_archive_directory_creation_failure_is_structured(): void {
		$zip = $this->create_zip( [
			'my-plugin.php' => $this->valid_plugin_header( 'My Plugin' ),
		] );
		$dir = $this->create_extract_dir();
		mkdir( $dir . '/my-plugin' );
		file_put_contents( $dir . '/my-plugin/existing.php', "<?php\n" );

		try {
			$this->extract( $zip, 'my-plugin', 'plugin', $dir );
			$this->fail( 'Expected extraction to fail when the flat-layout destination cannot be created.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'plugin_directory_create_failed', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
			$this->assertStringContainsString( 'destination plugin directory could not be created', $e->getMessage() );
		}

		$this->assertFileExists( $dir . '/my-plugin/existing.php' );
	}

	public function test_slug_matching_wrapper_takes_precedence_over_a_stray_root_plugin_header(): void {
		$zip = $this->create_zip( [
			'stray-plugin.php'             => $this->valid_plugin_header( 'Stray Plugin' ),
			'my-plugin/my-plugin.php'      => $this->valid_plugin_header( 'My Plugin' ),
			'my-plugin/includes/class.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertFileExists( $dir . '/my-plugin/includes/class.php' );
		$this->assertFileExists( $dir . '/stray-plugin.php' );
		$this->assertFileDoesNotExist( $dir . '/my-plugin/stray-plugin.php' );
	}

	/**
	 * @dataProvider wordpress_header_style_provider
	 */
	public function test_all_wordpress_header_comment_styles_are_recognized( string $bootstrap_contents ): void {
		$zip = $this->create_zip( [
			'my-plugin/my-plugin.php' => $bootstrap_contents,
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
	}

	/** @return array<string,array{string}> */
	public function wordpress_header_style_provider(): array {
		return [
			'docblock'              => [ "<?php\n/**\n * Plugin Name: My Plugin\n */\n" ],
			'line comment'          => [ "<?php\n// Plugin Name: My Plugin\n" ],
			'hash comment'          => [ "<?php\n# Plugin Name: My Plugin\n" ],
			'single-line block'     => [ "<?php /* Plugin Name: My Plugin */\n" ],
			'no space after colon'  => [ "<?php\n/*\nPlugin Name:My Plugin\n*/\n" ],
			'CR-only line endings'  => [ "<?php\r/**\r * Plugin Name: My Plugin\r */\r" ],
		];
	}

	public function test_matching_wrapper_directory_extracts_as_is(): void {
		$zip = $this->create_zip( [
			'my-plugin/my-plugin.php'         => $this->valid_plugin_header( 'My Plugin' ),
			'my-plugin/includes/helpers.php'  => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertFileExists( $dir . '/my-plugin/includes/helpers.php' );
	}

	public function test_matching_wrapper_directory_is_case_insensitive(): void {
		$zip = $this->create_zip( [
			'My-Plugin/my-plugin.php' => $this->valid_plugin_header( 'My Plugin' ),
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/My-Plugin/my-plugin.php' );
	}

	public function test_single_mismatched_wrapper_directory_is_renamed_to_slug(): void {
		$zip = $this->create_zip( [
			'my-plugin-v2/my-plugin.php'      => $this->valid_plugin_header( 'My Plugin' ),
			'my-plugin-v2/includes/other.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertFileExists( $dir . '/my-plugin/includes/other.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/my-plugin-v2' );
	}

	public function test_invalid_header_in_an_earlier_directory_does_not_steal_the_wrapper_rename(): void {
		$zip = $this->create_zip( [
			'includes/not-a-plugin.php'  => "<?php\n/* Plugin Name : Not A Plugin */\n",
			'my-plugin-v2/my-plugin.php' => $this->valid_plugin_header( 'My Plugin' ),
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertFileExists( $dir . '/includes/not-a-plugin.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/my-plugin-v2' );
	}

	public function test_plugin_wrapper_rename_failure_is_reported_as_a_structured_failure(): void {
		$zip = $this->create_zip( [
			'my-plugin-v2/my-plugin.php' => $this->valid_plugin_header( 'My Plugin' ),
		] );
		$dir = $this->create_extract_dir();
		mkdir( $dir . '/my-plugin' );
		file_put_contents( $dir . '/my-plugin/existing.php', "<?php\n" );

		try {
			$this->extract( $zip, 'my-plugin', 'plugin', $dir );
			$this->fail( 'Expected extraction to fail when the wrapper directory cannot be renamed.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'plugin_directory_rename_failed', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
			$this->assertStringContainsString( 'my-plugin-v2 could not be renamed to my-plugin', $e->getMessage() );
		}

		$this->assertDirectoryExists( $dir . '/my-plugin-v2' );
		$this->assertFileExists( $dir . '/my-plugin/existing.php' );
	}

	public function test_junk_top_level_directories_do_not_steal_the_wrapper_rename(): void {
		$zip = $this->create_zip( [
			'my-plugin-v2/my-plugin.php' => $this->valid_plugin_header( 'My Plugin' ),
			'__MACOSX/._my-plugin.php'   => "junk\n",
			'__MACOSX/._readme.txt'      => "junk\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/my-plugin.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/my-plugin-v2' );
	}

	public function test_archive_without_a_real_plugin_header_is_rejected(): void {
		$zip = $this->create_zip( [
			'my-plugin.php'         => $this->incidental_plugin_name_text( 'My_Plugin' ),
			'admin/class-admin.php' => $this->incidental_plugin_name_text( 'My_Plugin_Admin' ),
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-plugin', 'plugin', $dir );
			$this->fail( 'Expected extraction to fail without a valid plugin header.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'plugin_entry_point_not_found', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
		}
	}

	public function test_multiple_wrapper_candidates_without_root_bootstrap_select_the_first_detected_wrapper(): void {
		$zip = $this->create_zip( [
			'plugin-a/plugin-a.php' => $this->valid_plugin_header( 'Plugin A' ),
			'plugin-b/plugin-b.php' => $this->valid_plugin_header( 'Plugin B' ),
		] );
		$dir = $this->create_extract_dir();

		$output = $this->extract( $zip, 'my-plugin', 'plugin', $dir );

		$this->assertFileExists( $dir . '/my-plugin/plugin-a.php' );
		$this->assertFileExists( $dir . '/plugin-b/plugin-b.php' );
		$this->assertStringContainsString( 'Selecting the first detected directory: plugin-a.', $output );
	}

	public function test_flat_classic_theme_archive_extracts_into_slug_directory(): void {
		$zip = $this->create_zip( [
			'style.css'        => "/*\nTheme Name: My Theme\n*/\n",
			'index.php'        => "<?php\n",
			'assets/theme.css' => "body {}\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-theme/style.css' );
		$this->assertFileExists( $dir . '/my-theme/index.php' );
		$this->assertFileExists( $dir . '/my-theme/assets/theme.css' );
		$this->assertFileDoesNotExist( $dir . '/style.css' );
	}

	public function test_flat_block_theme_archive_extracts_into_slug_directory(): void {
		$zip = $this->create_zip( [
			'style.css'            => "/*\nTheme Name: My Block Theme\n*/\n",
			'templates/index.html' => "<!-- index -->\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-block-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-block-theme/style.css' );
		$this->assertFileExists( $dir . '/my-block-theme/templates/index.html' );
	}

	public function test_flat_theme_directory_creation_failure_is_structured(): void {
		$zip = $this->create_zip( [
			'style.css' => "/*\nTheme Name: My Theme\n*/\n",
			'index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();
		mkdir( $dir . '/my-theme' );
		file_put_contents( $dir . '/my-theme/existing.php', "<?php\n" );

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail when the flat theme destination cannot be created.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_directory_create_failed', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
			$this->assertStringContainsString( 'destination theme directory could not be created', $e->getMessage() );
		}

		$this->assertFileExists( $dir . '/my-theme/existing.php' );
	}

	public function test_theme_wrapper_is_anchored_to_the_directory_containing_style_css(): void {
		$zip = $this->create_zip( [
			'my-theme-build/style.css' => "/*\nTheme Name: My Theme\n*/\n",
			'my-theme-build/index.php' => "<?php\n",
			'__MACOSX/._style.css'     => "junk\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-theme/style.css' );
		$this->assertFileExists( $dir . '/my-theme/index.php' );
		$this->assertDirectoryDoesNotExist( $dir . '/my-theme-build' );
	}

	public function test_theme_header_with_cr_only_line_endings_is_recognized(): void {
		$zip = $this->create_zip( [
			'my-theme/style.css' => "/*\rTheme Name: My Theme\r*/\r",
			'my-theme/index.php' => "<?php\r",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-theme/style.css' );
		$this->assertFileExists( $dir . '/my-theme/index.php' );
	}

	public function test_empty_theme_stylesheet_is_reported_before_another_wrapper_is_selected(): void {
		$zip = $this->create_zip( [
			'empty-theme/style.css' => '',
			'valid-theme/style.css' => "/*\nTheme Name: Valid Theme\n*/\n",
			'valid-theme/index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail when a top-level theme stylesheet is empty.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_stylesheet_empty', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
			$this->assertStringContainsString( 'empty-theme/style.css is empty', $e->getMessage() );
		}

		$this->assertDirectoryDoesNotExist( $dir . '/my-theme' );
	}

	public function test_theme_wrapper_rename_failure_is_reported_as_a_structured_failure(): void {
		$zip = $this->create_zip( [
			'my-theme-build/style.css' => "/*\nTheme Name: My Theme\n*/\n",
			'my-theme-build/index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();
		mkdir( $dir . '/my-theme' );
		file_put_contents( $dir . '/my-theme/existing.php', "<?php\n" );

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail when the theme wrapper directory cannot be renamed.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_directory_rename_failed', $e->get_failure()['code'] );
			$this->assertSame( 'plugin_download', $e->get_failure()['stage'] );
			$this->assertStringContainsString( 'my-theme-build could not be renamed to my-theme', $e->getMessage() );
		}

		$this->assertDirectoryExists( $dir . '/my-theme-build' );
		$this->assertFileExists( $dir . '/my-theme/existing.php' );
	}

	public function test_stray_stylesheets_do_not_make_a_theme_archive_ambiguous(): void {
		$zip = $this->create_zip( [
			'my-theme-v2/style.css' => "/*\nTheme Name: My Theme\n*/\n",
			'my-theme-v2/index.php' => "<?php\n",
			'docs/style.css'        => "body { color: red; }\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-theme/style.css' );
		$this->assertDirectoryDoesNotExist( $dir . '/my-theme-v2' );
		$this->assertFileExists( $dir . '/docs/style.css' );
	}

	public function test_theme_archive_whose_only_style_css_lacks_a_theme_name_header_is_rejected(): void {
		$zip = $this->create_zip( [
			'my-theme/style.css' => "body { color: red; }\n",
			'my-theme/index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail without a Theme Name header.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_entry_point_not_found', $e->get_failure()['code'] );
		}
	}

	public function test_an_index_php_in_an_unrelated_directory_does_not_vouch_for_the_selected_theme(): void {
		$zip = $this->create_zip( [
			'theme-build/style.css' => "/*\nTheme Name: My Theme\n*/\n",
			'unrelated/index.php'   => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail when the selected theme directory has no index.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_index_missing', $e->get_failure()['code'] );
			$this->assertStringContainsString( 'theme-build', $e->getMessage() );
		}
	}

	public function test_block_theme_index_html_is_validated_in_the_selected_directory(): void {
		$zip = $this->create_zip( [
			'my-block-build/style.css'            => "/*\nTheme Name: My Block Theme\n*/\n",
			'my-block-build/templates/index.html' => "<!-- index -->\n",
		] );
		$dir = $this->create_extract_dir();

		$this->extract( $zip, 'my-block', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-block/templates/index.html' );
	}

	public function test_a_templates_index_html_in_an_unrelated_directory_does_not_vouch_for_the_selected_theme(): void {
		$zip = $this->create_zip( [
			'theme-build/style.css'           => "/*\nTheme Name: My Theme\n*/\n",
			'unrelated/templates/index.html'  => "<!-- index -->\n",
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail when the selected theme directory has no index.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_index_missing', $e->get_failure()['code'] );
		}
	}

	public function test_child_theme_without_index_files_is_accepted(): void {
		$zip = $this->create_zip( [
			'my-child-theme/style.css' => "/*\nTheme Name: My Child Theme\nTemplate: parent-theme\n*/\n",
		] );
		$dir = $this->create_extract_dir();

		$output = $this->extract( $zip, 'my-child-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-child-theme/style.css' );
		$this->assertStringContainsString( 'Found child theme. Parent theme is parent-theme.', $output );
	}

	public function test_theme_archive_with_multiple_style_css_directories_selects_the_first_detected_wrapper(): void {
		$zip = $this->create_zip( [
			'theme-a/style.css' => "/*\nTheme Name: Theme A\n*/\n",
			'theme-a/index.php' => "<?php\n",
			'theme-b/style.css' => "/*\nTheme Name: Theme B\n*/\n",
			'theme-b/index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		$output = $this->extract( $zip, 'my-theme', 'theme', $dir );

		$this->assertFileExists( $dir . '/my-theme/style.css' );
		$this->assertFileExists( $dir . '/my-theme/index.php' );
		$this->assertFileExists( $dir . '/theme-b/style.css' );
		$this->assertStringContainsString( 'Selecting the first detected directory: theme-a.', $output );
	}

	public function test_theme_archive_without_style_css_is_rejected(): void {
		$zip = $this->create_zip( [
			'my-theme/index.php' => "<?php\n",
		] );
		$dir = $this->create_extract_dir();

		try {
			$this->extract( $zip, 'my-theme', 'theme', $dir );
			$this->fail( 'Expected extraction to fail without style.css.' );
		} catch ( PluginDownloadException $e ) {
			$this->assertSame( 'theme_entry_point_not_found', $e->get_failure()['code'] );
		}
	}
}
