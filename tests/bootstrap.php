<?php

ini_set('error_reporting', E_ALL);

$files = array(
    __DIR__ . '/../vendor/autoload.php' /*independent testing*/,
    __DIR__ . '/../../../autoload.php'  /*testing as part of a larger package*/);

foreach ($files as $file) {
    if (file_exists($file)) {
        $loader = require $file;

        break;
    }
}

if (! isset($loader)) {
    throw new RuntimeException('vendor/autoload.php could not be found. Did you run `php composer.phar install`?');
}

$loader->add('Zoop\Shard\Test', __DIR__);
