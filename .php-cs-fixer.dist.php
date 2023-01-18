<?php
/*
* This document has been generated with
* https://mlocati.github.io/php-cs-fixer-configurator/#version:3.4.0|configurator
* you can change this configuration by importing this file.
*/
$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                'Cm/',
                'tests/',
            ])
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
    );
