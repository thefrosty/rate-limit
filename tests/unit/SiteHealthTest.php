<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use Symfony\Component\HttpFoundation\Request;
use function TheFrosty\RateLimit\isSiteHealthRequest;

final class SiteHealthTest extends TestCase
{
    /**
     * @return iterable<string, array{Request}>
     */
    public static function siteHealthRequests(): iterable
    {
        yield 'pretty permalink' => [self::request('/wp-json/wp-site-health/v1/tests/loopback-requests')];
        yield 'namespace index' => [self::request('/wp-json/wp-site-health/v1')];
        yield 'plain permalink' => [self::request('/?rest_route=/wp-site-health/v1/tests/https-status')];
    }

    /**
     * @dataProvider siteHealthRequests
     */
    public function test_site_health_routes_are_exempt(Request $request): void
    {
        self::assertTrue(isSiteHealthRequest($request));
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function nonSiteHealthRequests(): iterable
    {
        yield 'query rest_route override' => [self::request('/wp-json/wp-site-health/v1?rest_route=/wp/v2/users')];
        yield 'body rest_route override' => [
            self::request('/wp-json/wp-site-health/v1', 'POST', [], ['rest_route' => '/wp/v2/users']),
        ];
        yield 'array rest_route' => [self::request('/wp-json/wp-site-health/v1', 'GET', [], ['rest_route' => ['x']])];
        yield 'dot segments' => [self::request('/wp-json/wp-site-health/../wp/v2/users')];
        yield 'encoded dot segments' => [self::request('/wp-json/wp-site-health/%2E%2E/wp/v2/users')];
        yield 'look-alike namespace' => [self::request('/wp-json/wp-site-healthy/v1')];
        yield 'other REST route' => [self::request('/wp-json/wp/v2/posts')];
        yield 'front end' => [self::request('/wp-site-health/')];
    }

    /**
     * @dataProvider nonSiteHealthRequests
     */
    public function test_other_routes_are_not_exempt(Request $request): void
    {
        self::assertFalse(isSiteHealthRequest($request));
    }

    public function test_rest_route_override_is_rate_limited(): void
    {
        $factory = static fn(): Request => self::request('/wp-json/wp-site-health/v1?rest_route=/wp/v2/users');

        self::assertLessThan(50, self::allowedBeforeLimit($factory));
    }

    public function test_missing_request_uri_does_not_throw_type_error(): void
    {
        // Previously str_starts_with(null, ...) threw a TypeError under strict_types.
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => self::CLIENT_IP, 'REQUEST_METHOD' => 'GET']);

        self::assertNull(self::send($request));
        self::assertNotFalse(get_transient(self::transientKey(self::CLIENT_IP)));
    }
}
