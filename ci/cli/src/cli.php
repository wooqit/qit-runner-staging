<?php

use CI_CLI\Commands\UpdateReportCommand;
use lucatume\DI52\Container;
use CI_CLI\App;
use CI_CLI\Commands\NotifyCommand;
use CI_CLI\Commands\DownloadPluginCommand;
use CI_CLI\Commands\HeaderCommand;
use CI_CLI\Commands\ThemeCommand;
use Symfony\Component\Console\Application;

try {
	require_once __DIR__ . '/../vendor/autoload.php';
	require_once __DIR__ . '/helpers.php';


	$container = new Container();
	App::setContainer( $container );

	$application = new Application();
	$application->find( 'completion' )->setHidden( true );
	$application->find( 'list' )->setHidden( true );
	$application->find( 'help' )->setHidden( true );
	$application->add( $container->make( NotifyCommand::class ) );
	$application->add( $container->make( DownloadPluginCommand::class ) );
	$application->add( $container->make( UpdateReportCommand::class ) );
	$application->add( $container->make( HeaderCommand::class ) );
	$application->add( $container->make( ThemeCommand::class ) );
	$application->run();
} catch ( \Exception $e ) {
	echo $e->getMessage();
	exit( 1 );
}
