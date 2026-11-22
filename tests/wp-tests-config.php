<?php

declare(strict_types=1);

/**
 * WordPress test suite configuration (wp-phpunit/wp-phpunit).
 * WordPress core is installed to /wordpress by roots/wordpress. The test database is DROPPED and recreated.
 */

define('ABSPATH', dirname(__DIR__) . '/wordpress/');
define('WP_DEFAULT_THEME', 'default');
define('WP_DEBUG', true);

define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASS') ?: 'root');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Test-only salts; never used outside the throwaway test database.
define('AUTH_KEY', 'rate-limit-tests-auth-key');
define('SECURE_AUTH_KEY', 'rate-limit-tests-secure-auth-key');
define('LOGGED_IN_KEY', 'rate-limit-tests-logged-in-key');
define('NONCE_KEY', 'rate-limit-tests-nonce-key');
define('AUTH_SALT', 'rate-limit-tests-auth-salt');
define('SECURE_AUTH_SALT', 'rate-limit-tests-secure-auth-salt');
define('LOGGED_IN_SALT', 'rate-limit-tests-logged-in-salt');
define('NONCE_SALT', 'rate-limit-tests-nonce-salt');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
