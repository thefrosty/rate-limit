<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use Symfony\Component\HttpFoundation\Request;
use WP_UnitTestCase;
use WPDieException;
use function TheFrosty\RateLimit\maybeRateLimit;

/**
 * Base test case running against a real WordPress (wp-phpunit).
 *
 * wp_send_json() calls die() outside of Ajax, so tests force wp_doing_ajax() and route both wp_die() handlers to a
 * WPDieException carrying the HTTP status passed to wp_die(), which lets a 429 be asserted on.
 */
abstract class TestCase extends WP_UnitTestCase
{
    protected const string CLIENT_IP = '203.0.113.5';

    public function set_up(): void
    {
        parent::set_up();

        $handler = static fn(): callable => static function (mixed $message, mixed $title = '', mixed $args = []): never {
            $status = is_array($args) && isset($args['response']) ? (int)$args['response'] : 0;
            throw new WPDieException(is_scalar($message) ? (string)$message : '', $status);
        };
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', $handler, 99);
        add_filter('wp_die_handler', $handler, 99);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $parameters
     */
    protected static function request(
        string $uri = '/wp-json/wp/v2/posts',
        string $method = 'GET',
        array $server = [],
        array $parameters = [],
    ): Request {
        return Request::create(
            $uri,
            $method,
            $parameters,
            [],
            [],
            $server + ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_ACCEPT' => 'application/json']
        );
    }

    protected static function transientKey(string $ip): string
    {
        return sprintf('_thefrosty_rate_limit_%s', md5($ip));
    }

    /**
     * Run the limiter for one request.
     * @return array{status: int, body: string}|null Null when the request was allowed through.
     */
    protected static function send(Request $request): ?array
    {
        ob_start();
        try {
            maybeRateLimit($request);
            return null;
        } catch (WPDieException $exception) {
            $output = (string)ob_get_contents();
            return ['status' => $exception->getCode(), 'body' => $output !== '' ? $output : $exception->getMessage()];
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Send requests until one is rate limited; returns how many were allowed through.
     * @param callable(): Request $factory
     */
    protected static function allowedBeforeLimit(callable $factory, int $max = 50): int
    {
        for ($i = 0; $i < $max; $i++) {
            if (self::send($factory()) !== null) {
                return $i;
            }
        }
        return $max;
    }
}
