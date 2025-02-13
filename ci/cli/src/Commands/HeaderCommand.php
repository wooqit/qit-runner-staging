<?php

namespace CI_CLI\Commands;

use CI_CLI\App;
use CI_CLI\HeaderHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function CI_CLI\validate_env_vars;

class HeaderCommand extends Command {
	protected HeaderHandler $header_handler;

	private array $expected_vars =  [
		'PLUGIN_DIRECTORY',
		'PLUGIN_TYPE',
		'PLUGIN_SLUG'
	];

	public function __construct() {
		parent::__construct();
		$this->header_handler = App::make( HeaderHandler::class );
	}

	protected function configure(): void {
		$this
			->setName( 'header' )
			->setDescription( 'Interact with plugins headers.' )
			->addOption( 'single-header', 's', InputOption::VALUE_REQUIRED, 'Fetch a header item from the plugin header comment block' )
			->addOption( 'all-headers', 'a', InputOption::VALUE_NONE, 'All plugin Header Info' )
			->addOption( 'output', 'o', InputOption::VALUE_REQUIRED, 'Set header info as step output' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		validate_env_vars( $this->expected_vars );

		$plugin_directory = getenv( 'PLUGIN_DIRECTORY' );

		if ( getenv( 'PLUGIN_TYPE' ) === 'theme' ) {
			$plugin_entry_point = $plugin_directory . '/style.css';
		} else {
			$plugin_entry_point = $plugin_directory . '/' . getenv( 'PLUGIN_SLUG' ) . '.php';
		}

		if ( $input->getOption( 'single-header' ) ) {
			$header           = $this->header_handler->fetch_single_plugin_header_item( $plugin_entry_point, $input->getOption( 'single-header' ) );

			if ( empty( $header ) ) {
				$output->writeln( 'Header item not found.' );
				return Command::FAILURE;
			}

			$output->writeln( $header );

			return Command::SUCCESS;
		}

		if ( $input->getOption( 'all-headers' ) ) {
			$header           = $this->header_handler->fetch_common_header_info( $plugin_entry_point );
			$output->writeln( json_encode( $header, JSON_PRETTY_PRINT ) );

			return Command::SUCCESS;
		}

		if ( $input->getOption( 'output' ) ) {
			$header           = $this->header_handler->fetch_single_plugin_header_item( $plugin_entry_point, $input->getOption( 'output' ) );

			if ( empty( $header ) ) {
				$output->writeln( 'Header item not found.' );
				$header = 'Undefined';
			}

			$output->writeln( $header );
			$command_string = 'echo "' . "header={$header}". '" >> "$GITHUB_OUTPUT"';

			$output->writeln( "Running: " . $command_string );
			passthru( $command_string );

			return Command::SUCCESS;
		}

		$output->writeln( 'No option selected.' );
		return Command::FAILURE;
	}
}