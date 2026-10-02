<?php

use lucatume\DI52\App;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

$container = new \lucatume\DI52\Container();
App::setContainer( $container );