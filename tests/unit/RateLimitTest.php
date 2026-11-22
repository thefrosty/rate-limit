<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use stdClass;
use Symfony\Component\HttpFoundation\Request;
use const TheFrosty\RateLimit\MAX_REQUESTS_PER_MINUTE;

final class RateLimitTest extends TestCase
{
    public function test_first_request_starts_a_window(): void
    {
        self::assertNull(self::send(self::request()));

        $data = get_transient(self::transientKey(self::CLIENT_IP));
        self::assertInstanceOf(stdClass::class, $data);
        self::assertSame(0, $data->limits[self::CLIENT_IP]['count']);
        self::assertEqualsWithDelta(time(), $data->limits[self::CLIENT_IP]['start_time'], 2);
    }

    public function test_requests_over_the_limit_get_a_json_error(): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request());

        // Current behaviour: the first request is counted as 0, so MAX + 1 requests get through.
        self::assertSame(MAX_REQUESTS_PER_MINUTE + 1, $allowed);

        $response = self::send(self::request());
        self::assertNotNull($response);
        self::assertSame(['error' => 'Too Many Requests.'], json_decode($response['body'], true));
    }

    public function test_html_clients_get_wp_die_429(): void
    {
        $factory = static fn(): Request => self::request('/', 'GET', ['HTTP_ACCEPT' => 'text/html']);
        for ($i = 0; $i <= MAX_REQUESTS_PER_MINUTE; $i++) {
            self::assertNull(self::send($factory()));
        }

        self::assertSame(['status' => 429, 'body' => 'Too Many Requests.'], self::send($factory()));
    }

    public function test_expired_window_resets_the_count(): void
    {
        $data = new stdClass();
        $data->limits[self::CLIENT_IP] = ['count' => 500, 'start_time' => time() - MINUTE_IN_SECONDS - 1];
        set_transient(self::transientKey(self::CLIENT_IP), $data, MINUTE_IN_SECONDS);

        self::assertNull(self::send(self::request()));

        $stored = get_transient(self::transientKey(self::CLIENT_IP));
        self::assertSame(1, $stored->limits[self::CLIENT_IP]['count']);
    }

    public function test_clients_have_separate_buckets(): void
    {
        self::allowedBeforeLimit(static fn(): Request => self::request());

        // A different client is unaffected by the first client being limited.
        self::assertNull(self::send(self::request(server: ['REMOTE_ADDR' => '198.51.100.7'])));
        self::assertNotFalse(get_transient(self::transientKey('198.51.100.7')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neverLimitedIps(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
    }

    /**
     * @dataProvider neverLimitedIps
     */
    public function test_loopback_is_never_limited(string $ip): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request(server: ['REMOTE_ADDR' => $ip]));

        self::assertSame(50, $allowed);
        self::assertFalse(get_transient(self::transientKey($ip)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skippedMethods(): iterable
    {
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    /**
     * @dataProvider skippedMethods
     */
    public function test_head_and_options_are_not_counted(string $method): void
    {
        self::assertNull(self::send(self::request(method: $method)));
        self::assertFalse(get_transient(self::transientKey(self::CLIENT_IP)));
    }

    public function test_administrators_are_not_limited(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        self::assertSame(50, self::allowedBeforeLimit(static fn(): Request => self::request()));
        self::assertFalse(get_transient(self::transientKey(self::CLIENT_IP)));
    }

    public function test_logged_in_non_administrators_are_limited(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        self::assertSame(MAX_REQUESTS_PER_MINUTE + 1, self::allowedBeforeLimit(static fn(): Request => self::request()));
    }

    public function test_site_health_endpoint_is_not_limited(): void
    {
        $factory = static fn(): Request => self::request('/wp-json/wp-site-health/v1/tests/loopback-requests');

        self::assertSame(50, self::allowedBeforeLimit($factory));
        self::assertFalse(get_transient(self::transientKey(self::CLIENT_IP)));
    }
}
