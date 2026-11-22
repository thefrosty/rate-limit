<?php

declare(strict_types=1);

/**
 * Minimal WordPress stand-ins so the rate limiter can be unit tested without a WordPress install.
 * Only the functions, constants and classes rate-limit.php (and wp-utilities' request helpers) touch are stubbed.
 */

const MINUTE_IN_SECONDS = 60;

final class WP_Http
{
    public const int TOO_MANY_REQUESTS = 429;
}

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, int> */
    public static array $expirations = [];
    public static bool $loggedIn = false;
    /** @var string[] */
    public static array $caps = [];
    /** @var array<string, callable> */
    public static array $filters = [];

    public static function reset(): void
    {
        self::$transients = [];
        self::$expirations = [];
        self::$loggedIn = false;
        self::$caps = [];
        self::$filters = [];
    }
}

/** Thrown in place of wp_die() so tests can assert on it. */
final class WpDieException extends RuntimeException
{
}

/** Thrown in place of wp_send_json() (which exits) so tests can assert on it. */
final class WpSendJsonException extends RuntimeException
{
    public function __construct(public readonly mixed $response, public readonly ?int $status)
    {
        parent::__construct('wp_send_json', (int)$status);
    }
}

function get_transient(string $transient): mixed
{
    return WpStub::$transients[$transient] ?? false;
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    // Store a copy, like the object cache/DB round-trip would.
    WpStub::$transients[$transient] = is_object($value) ? clone $value : $value;
    WpStub::$expirations[$transient] = $expiration;
    return true;
}

function is_user_logged_in(): bool
{
    return WpStub::$loggedIn;
}

function current_user_can(string $capability, mixed ...$args): bool
{
    return in_array($capability, WpStub::$caps, true);
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $str): string
{
    return trim(strip_tags($str));
}

function absint(mixed $maybeint): int
{
    return abs((int)$maybeint);
}

function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
{
    return isset(WpStub::$filters[$hook_name]) ? (WpStub::$filters[$hook_name])($value, ...$args) : $value;
}

function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
{
    throw new WpDieException((string)$message, is_int($title) ? $title : 500);
}

function wp_send_json(mixed $response, ?int $status_code = null, int $flags = 0): never
{
    throw new WpSendJsonException($response, $status_code);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/rate-limit.php';
