<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// wp-phpunit/wp-phpunit sets WP_PHPUNIT__DIR from its Composer autoload file.
$testsDir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');

if (!$testsDir || !is_file($testsDir . '/includes/functions.php')) {
    fwrite(STDERR, "WordPress test library not found; run `composer install`.\n");
    exit(1);
}

// Gives access to tests_add_filter().
require_once $testsDir . '/includes/functions.php';

// Load the mu-plugin the way WordPress would.
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/rate-limit.php';
});

require $testsDir . '/includes/bootstrap.php';
