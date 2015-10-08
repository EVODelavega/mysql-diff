#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Diff\Command\DiffCommand;
use Diff\Command\CompareCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new DiffCommand());
$application->add(new CompareCommand());
$application->run();