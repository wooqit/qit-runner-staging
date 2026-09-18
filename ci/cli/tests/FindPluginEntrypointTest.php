<?php

use Symfony\Component\Process\Process;

class FindPluginEntrypointTest extends \PHPUnit\Framework\TestCase {
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

	private function create_sut_directory( string $slug ): string {
		$parent = sys_get_temp_dir() . '/qit-entrypoint-' . uniqid( '', true );
		mkdir( $parent );
		mkdir( $parent . '/' . $slug );
		$this->temp_paths[] = $parent;

		return $parent;
	}

	private function run_entrypoint_finder(
		string $parent,
		string $slug,
		string $type,
		string $failure_output = '',
		string $github_output = ''
	): Process {
		$process = new Process( [
			PHP_BINARY,
			dirname( __DIR__, 2 ) . '/bin/find-plugin-entrypoint.php',
			$parent,
			$slug,
			$type,
		], null, [
			'GITHUB_OUTPUT'     => $github_output,
			'QIT_FAILURE_OUTPUT' => $failure_output,
		] );
		$process->run();

		return $process;
	}

	public function test_missing_parent_directory_writes_a_structured_failure(): void {
		$parent       = sys_get_temp_dir() . '/qit-missing-entrypoint-parent-' . uniqid( '', true );
		$failure_path = sys_get_temp_dir() . '/qit-entrypoint-failure-' . uniqid( '', true ) . '.json';
		$this->temp_paths[] = $failure_path;

		$process = $this->run_entrypoint_finder( $parent, 'my-plugin', 'plugin', $failure_path );

		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringContainsString( 'Parent directory does not exist', $process->getErrorOutput() );
		$this->assertSame(
			[
				'stage'   => 'setup',
				'code'    => 'sut_parent_directory_not_found',
				'message' => 'The parent directory for extracted plugin my-plugin does not exist.',
			],
			json_decode( (string) file_get_contents( $failure_path ), true )
		);
	}

	public function test_nested_plugin_entrypoint_is_emitted_relative_to_the_plugin_directory(): void {
		$parent = $this->create_sut_directory( 'my-plugin' );
		mkdir( $parent . '/my-plugin/includes' );
		$entrypoint = $parent . '/my-plugin/includes/bootstrap.php';
		file_put_contents(
			$entrypoint,
			"<?php\n/**\n * Plugin Name: My Plugin\n */\n"
		);
		$github_output = sys_get_temp_dir() . '/qit-entrypoint-output-' . uniqid( '', true );
		$this->temp_paths[] = $github_output;

		$process = $this->run_entrypoint_finder( $parent, 'my-plugin', 'plugin', '', $github_output );

		$this->assertSame( 0, $process->getExitCode(), $process->getErrorOutput() );
		$this->assertStringContainsString( 'Entry Point: includes/bootstrap.php', $process->getOutput() );
		$this->assertStringContainsString( 'entry_point=includes/bootstrap.php', (string) file_get_contents( $github_output ) );
		$this->assertFileExists( $parent . '/my-plugin/includes/bootstrap.php' );
		$this->assertFileDoesNotExist( $parent . '/my-plugin/bootstrap.php' );
	}

	public function test_archive_controlled_shell_syntax_is_written_literally_to_github_output(): void {
		$parent     = $this->create_sut_directory( 'my-plugin' );
		$entrypoint = '$(printf qit-injected).php';
		file_put_contents(
			$parent . '/my-plugin/' . $entrypoint,
			"<?php\n/**\n * Plugin Name: My Plugin\n */\n"
		);
		$github_output = sys_get_temp_dir() . '/qit-entrypoint-output-' . uniqid( '', true );
		$this->temp_paths[] = $github_output;

		$process = $this->run_entrypoint_finder( $parent, 'my-plugin', 'plugin', '', $github_output );

		$this->assertSame( 0, $process->getExitCode(), $process->getErrorOutput() );
		$this->assertSame(
			"plugin_directory=my-plugin\nentry_point={$entrypoint}\n",
			(string) file_get_contents( $github_output )
		);
	}

	public function test_entrypoint_with_a_line_break_is_rejected_as_a_workflow_output(): void {
		$parent     = $this->create_sut_directory( 'my-plugin' );
		$entrypoint = "plugin\ninjected=value.php";
		file_put_contents(
			$parent . '/my-plugin/' . $entrypoint,
			"<?php\n/**\n * Plugin Name: My Plugin\n */\n"
		);
		$github_output  = sys_get_temp_dir() . '/qit-entrypoint-output-' . uniqid( '', true );
		$failure_output = sys_get_temp_dir() . '/qit-entrypoint-failure-' . uniqid( '', true ) . '.json';
		$this->temp_paths[] = $github_output;
		$this->temp_paths[] = $failure_output;

		$process = $this->run_entrypoint_finder( $parent, 'my-plugin', 'plugin', $failure_output, $github_output );

		$this->assertSame( 1, $process->getExitCode() );
		$this->assertStringContainsString( 'Could not write entry point workflow outputs', $process->getErrorOutput() );
		$this->assertFileDoesNotExist( $github_output );
		$this->assertSame(
			'sut_entry_point_output_failed',
			json_decode( (string) file_get_contents( $failure_output ), true )['code']
		);
	}

	public function test_plugin_header_with_cr_only_line_endings_is_recognized(): void {
		$parent = $this->create_sut_directory( 'my-plugin' );
		file_put_contents(
			$parent . '/my-plugin/my-plugin.php',
			"<?php\r/**\r * Plugin Name: My Plugin\r */\r"
		);

		$process = $this->run_entrypoint_finder( $parent, 'my-plugin', 'plugin' );

		$this->assertSame( 0, $process->getExitCode(), $process->getErrorOutput() );
		$this->assertStringContainsString( 'Entry Point: my-plugin.php', $process->getOutput() );
	}

	public function test_theme_header_with_cr_only_line_endings_is_recognized(): void {
		$parent = $this->create_sut_directory( 'my-theme' );
		file_put_contents(
			$parent . '/my-theme/style.css',
			"/*\rTheme Name: My Theme\r*/\r"
		);

		$process = $this->run_entrypoint_finder( $parent, 'my-theme', 'theme' );

		$this->assertSame( 0, $process->getExitCode(), $process->getErrorOutput() );
		$this->assertStringContainsString( 'Entry Point: style.css', $process->getOutput() );
	}
}
