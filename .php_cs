<?php

$config = PhpCsFixer\Config::create();
$config->setRules([
    '@PSR2' => true,
]);

$finder = PhpCsFixer\Finder::create();
$finder->in([
    'src',
    'test',
]);

$config->setFinder($finder);

return $config;
