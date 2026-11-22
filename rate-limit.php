<?php

declare(strict_types=1);

/**
 * Plugin Name: Rate Limit
 * Description: Rate Limit API.
 * Version: 0.1
 * Author: Austin Passy
 * Author URI: https://austin.passy.co
 * @ref https://wp-tutorials.tech/optimise-wordpress/rate-limit-wordpress-api-calls/
 * @ref https://github.com/fuzzywalrus/wordpress-rate-limiter/
 */

namespace TheFrosty\RateLimit;

use stdClass;
use Symfony\Component\HttpFoundation\Request;
use WP_Http;
use function apply_filters;
use function array_filter;
use function array_key_exists;
use function array_values;
use function current_user_can;
use function defined;
use function filter_var;
use function function_exists;
use function header;
use function headers_sent;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function is_user_logged_in;
use function ltrim;
use function rawurldecode;
use function max;
use function md5;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function TheFrosty\WpUtilities\getRequest;
use function time;
use function wp_send_json;
use const FILTER_VALIDATE_IP;
use const MINUTE_IN_SECONDS;
use const WP_CLI;

const MAX_REQUESTS_PER_MINUTE = 10;
const NEVER_RATE_LIMITED_IPS = [
    '127.0.0.1',
    '::1',
];

/**
 * Resolve the client IP without trusting client-supplied headers.
 * Uses REMOTE_ADDR unless REMOTE_ADDR is a proxy registered via the `thefrosty/rate_limit/trusted_proxies`
 * filter (IPs or CIDR ranges), in which case the X-Forwarded-For chain is walked by Symfony.
 * @param Request $request
 * @return string|null
 */
function getClientIp(Request $request): ?string
{
    $proxies = apply_filters('thefrosty/rate_limit/trusted_proxies', []);
    if (is_array($proxies) && $proxies !== []) {
        Request::setTrustedProxies(array_values(array_filter($proxies, 'is_string')), Request::HEADER_X_FORWARDED_FOR);
    }

    $ip = $request->getClientIp();

    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/**
 * Whether the request will be dispatched to the core Site Health REST namespace.
 * WordPress lets a `rest_route` query/body parameter override the pretty-permalink path, so it must be checked too;
 * matching on the raw REQUEST_URI let `/wp-json/wp-site-health?rest_route=/any/route` skip the limiter.
 * @param Request $request
 * @return bool
 */
function isSiteHealthRequest(Request $request): bool
{
    $routes = [];
    foreach ([$request->query->all(), $request->request->all()] as $params) {
        if (array_key_exists('rest_route', $params)) {
            if (!is_string($params['rest_route'])) {
                return false;
            }
            $routes[] = $params['rest_route'];
        }
    }

    if ($routes === []) {
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/wp-json/')) {
            return false;
        }
        $routes[] = substr($path, strlen('/wp-json'));
    }

    foreach ($routes as $route) {
        $route = '/' . ltrim(rawurldecode($route), '/');
        if (str_contains($route, '..') || !str_starts_with($route, '/wp-site-health/')) {
            return false;
        }
    }

    return true;
}

/**
 * Maybe send a Rate-Limit header and return a JSON 429 response.
 * @param Request|null $request
 */
function maybeRateLimit(?Request $request = null): void
{
    $request ??= getRequest();
    $ip = getClientIp($request);

    if (
        // Wrap with function_exists in case we call this earlier then it's available.
        (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) ||
        (defined('DOING_CRON') && DOING_CRON) ||
        (defined('WP_CLI') && WP_CLI) ||
        in_array($request->getMethod(), ['HEAD', 'OPTIONS'], true) ||
        isSiteHealthRequest($request)
    ) {
        return;
    }

    if ($ip === null) {
        return;
    }

    if (in_array($ip, NEVER_RATE_LIMITED_IPS, true)) {
        return;
    }

    // Hash the IP: sanitize_key() strips ':' so distinct IPv6 addresses (e.g. 1:23:: and 12:3::) shared a bucket.
    $transient = sprintf('_thefrosty_rate_limit_%s', md5($ip));
    $rate_limit = get_transient($transient);

    // Increment the current IP count.
    if (is_object($rate_limit) && !empty($rate_limit->limits) && !empty($rate_limit->limits[$ip])) {
        if ((time() - $rate_limit->limits[$ip]['start_time']) > MINUTE_IN_SECONDS) {
            $rate_limit->limits[$ip]['count'] = 0; // Reset if the start time is greater than 1 minute ago.
            $rate_limit->limits[$ip]['start_time'] = time(); // Update the timer.
        }
        $rate_limit->limits[$ip]['count']++;
        $remaining = max(0, (int)floor(MAX_REQUESTS_PER_MINUTE - $rate_limit->limits[$ip]['count']));
        if (!headers_sent()) {
            header(sprintf('X-RateLimit-Limit: %d', MAX_REQUESTS_PER_MINUTE));
            header(sprintf('X-RateLimit-Remaining: %s', $remaining));
            header(sprintf('X-RateLimit-Window: %d', MINUTE_IN_SECONDS));
        }
        set_transient($transient, $rate_limit, MINUTE_IN_SECONDS * 5);
    }

    // Set the data.
    if ($rate_limit === false) {
        $rate_limit = new stdClass();
        $rate_limit->limits[$ip] = [
            'count' => 1, // This request counts toward the limit.
            'start_time' => time(),
        ];
        set_transient($transient, $rate_limit, MINUTE_IN_SECONDS);
    }

    if (!empty($rate_limit->limits) && !empty($rate_limit->limits[$ip])) {
        $is_rate_limited = $rate_limit->limits[$ip]['count'] > MAX_REQUESTS_PER_MINUTE;
    }

    if (!isset($is_rate_limited) || !$is_rate_limited) {
        return;
    }

    if (!headers_sent()) {
        header(sprintf('X-RateLimit-Reset: %d', $rate_limit->limits[$ip]['start_time'] + MINUTE_IN_SECONDS));
        header(
            sprintf('Retry-After: %d', max(0, $rate_limit->limits[$ip]['start_time'] + MINUTE_IN_SECONDS - time()))
        );
    }

    if (str_contains($request->headers->get('Accept', '*/*'), 'html')) {
        wp_die('Too Many Requests.', WP_Http::TOO_MANY_REQUESTS);
    }

    // HTTP Response 429 => "Too Many Requests".
    wp_send_json(['error' => 'Too Many Requests.'], WP_Http::TOO_MANY_REQUESTS);
}
