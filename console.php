#!/usr/bin/env php
<?php

$app = require __DIR__.'/app.php';
$app->register(new Knp\Provider\ConsoleServiceProvider(), [
    'console.name' => 'Wikimedia Relevance Scorer',
    'console.version' => '0.0.1',
    'console.project_directory' => __DIR__,
]);

/** @var Knp\Console\Application $console */
$console = $app['console'];
foreach ($app['search.console'] as $serviceId) {
    $console->add($app[$serviceId]);
}
$console->run();
