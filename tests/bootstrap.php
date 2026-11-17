<?php

/*
 * Test bootstrap.
 *
 * Normally the package's own vendor/ (from `composer install` in this folder) is used.
 * When it is missing, fall back to the autoloader of a host Laravel app that has this
 * package installed (path set via ONTRAPORT_TEST_AUTOLOAD, or the sibling ../app harness).
 */

$candidates = array_filter([
    __DIR__ . '/../vendor/autoload.php',
    getenv('ONTRAPORT_TEST_AUTOLOAD') ?: null,
    __DIR__ . '/../../app/vendor/autoload.php',
]);

$loader = null;
foreach ($candidates as $file) {
    if (is_file($file)) {
        $loader = require $file;
        break;
    }
}

if (! $loader instanceof \Composer\Autoload\ClassLoader) {
    fwrite(STDERR, "No autoloader found. Run `composer install` in the package directory.\n");
    exit(1);
}

// Make sure the package sources and test classes in *this* checkout are used.
$loader->addPsr4('Linups\\OntraportLaravel\\Tests\\', __DIR__, true);
$loader->addPsr4('Linups\\OntraportLaravel\\', dirname(__DIR__) . '/src', true);
