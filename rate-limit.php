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
use function current_user_can;
use function defined;
use function function_exists;
use function header;
use function headers_sent;
use function in_array;
use function is_object;
use function is_user_logged_in;
use function max;
use function sanitize_key;
use function sprintf;
use function str_contains;
use function TheFrosty\WpUtilities\getIpAddress;
use function TheFrosty\WpUtilities\getRequest;
use function time;
use function wp_send_json;
use const MINUTE_IN_SECONDS;
use const WP_CLI;

const MAX_REQUESTS_PER_MINUTE = 10;
const NEVER_RATE_LIMITED_IPS = [
    '127.0.0.1',
    '::1',
];

/**
 * Maybe send a Rate-Limit header and return a JSON 429 response.
 * @param Request|null $request
 */
function maybeRateLimit(?Request $request = null): void
{
    $request ??= getRequest();
    $ip = getIpAddress($request);

    if (
        // Wrap with function_exists in case we call this earlier then it's available.
        (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) ||
        (defined('DOING_CRON') && DOING_CRON) ||
        (defined('WP_CLI') && WP_CLI) ||
        in_array($request->getMethod(), ['HEAD', 'OPTIONS'], true) ||
        str_starts_with($request->server->get('REQUEST_URI'), '/wp-json/wp-site-health')
    ) {
        return;
    }

    if ($ip === null) {
        return;
    }

    if (in_array($ip, NEVER_RATE_LIMITED_IPS, true)) {
        return;
    }

    $transient = sprintf('_thefrosty_rate_limit_%s', sanitize_key($ip));
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
            'count' => 0,
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
        $reset = $rate_limit->limits[$ip]['start_time'] + MINUTE_IN_SECONDS;
        header(sprintf('X-RateLimit-Reset: %d', $reset));
        // Seconds until the window resets (never negative).
        header(sprintf('Retry-After: %d', max(0, $reset - time())));
    }

    if (str_contains($request->headers->get('Accept', '*/*'), 'html')) {
        wp_die('Too Many Requests.', WP_Http::TOO_MANY_REQUESTS);
    }

    // HTTP Response 429 => "Too Many Requests".
    wp_send_json(['error' => 'Too Many Requests.'], WP_Http::TOO_MANY_REQUESTS);
}
