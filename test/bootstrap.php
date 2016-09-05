<?php

$loaderFile = __DIR__.'/../vendor/autoload.php';

if (!is_file($loaderFile)) {
    throw new \RuntimeException(sprintf(
        'Could not find autoload file (expected %s). Did you run "composer install --dev"?',
        $loaderFile
    ));
}

$loader = require $loaderFile;

$loader->add('Sentry\SentryBundle\Test', __DIR__);
