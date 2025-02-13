<?php

namespace CI_CLI\Commands;

use CI_CLI\App;
use CI_CLI\HeaderHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ThemeCommand extends Command {
	protected HeaderHandler $header_handler;

	private array $expected_vars =  [
		'PLUGIN_DIRECTORY',
		'PLUGIN_ENTRYPOINT'
	];

	public function __construct() {
		parent::__construct();
		$this->header_handler = App::make( HeaderHandler::class );
	}

	protected function configure(): void {
		$this
			->setName( 'theme' )
			->setDescription( 'Interact with themes.' )
			->setHelp( 'This command allows you to interact with themes.' )
			->addOption( 'install-parent', 'p', InputOption::VALUE_NONE, 'Install the parent theme.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		if ( $input->getOption( 'install-parent' ) ) {
			$entrypoint       = getenv( 'PLUGIN_ENTRYPOINT' );
			$header           = $this->header_handler->fetch_single_plugin_header_item( $entrypoint, 'Template' );

			if ( empty( $header ) ) {
				$output->writeln( 'Theme is not a child theme.' );
				return Command::SUCCESS;
			}

			$command_string = sprintf( 'docker exec --user=www-data ci_runner_php_fpm bash -c "wp theme install %s"', $header );

			passthru( $command_string, $result_code );

			if ( $result_code !== 0 ) {
				$output->writeln( "Failed to install parent theme: $header" );
				return Command::FAILURE;
			}

			return Command::SUCCESS;
		}

		$output->writeln( 'No command specified.' );
		return Command::FAILURE;
	}
}