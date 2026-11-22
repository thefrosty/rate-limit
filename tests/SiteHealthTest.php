<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WpSendJsonException;
use WpStub;
use function TheFrosty\RateLimit\isSiteHealthRequest;
use function TheFrosty\RateLimit\maybeRateLimit;

final class SiteHealthTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function request(string $uri, string $method = 'GET', array $parameters = []): Request
    {
        return Request::create(
            $uri,
            $method,
            $parameters,
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_ACCEPT' => 'application/json']
        );
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function siteHealthRequests(): iterable
    {
        yield 'pretty permalink' => [self::request('/wp-json/wp-site-health/v1/tests/loopback-requests')];
        yield 'namespace index' => [self::request('/wp-json/wp-site-health/v1')];
        yield 'plain permalink' => [self::request('/?rest_route=/wp-site-health/v1/tests/https-status')];
    }

    #[DataProvider('siteHealthRequests')]
    public function testSiteHealthRoutesAreExempt(Request $request): void
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
            self::request('/wp-json/wp-site-health/v1', 'POST', ['rest_route' => '/wp/v2/users']),
        ];
        yield 'array rest_route' => [self::request('/wp-json/wp-site-health/v1', 'GET', ['rest_route' => ['x']])];
        yield 'dot segments' => [self::request('/wp-json/wp-site-health/../wp/v2/users')];
        yield 'encoded dot segments' => [self::request('/wp-json/wp-site-health/%2E%2E/wp/v2/users')];
        yield 'look-alike namespace' => [self::request('/wp-json/wp-site-healthy/v1')];
        yield 'other REST route' => [self::request('/wp-json/wp/v2/posts')];
        yield 'front end' => [self::request('/wp-site-health/')];
    }

    #[DataProvider('nonSiteHealthRequests')]
    public function testOtherRoutesAreNotExempt(Request $request): void
    {
        self::assertFalse(isSiteHealthRequest($request));
    }

    public function testRestRouteOverrideIsRateLimited(): void
    {
        $this->expectException(WpSendJsonException::class);
        for ($i = 0; $i < 50; $i++) {
            maybeRateLimit(self::request('/wp-json/wp-site-health/v1?rest_route=/wp/v2/users'));
        }
    }

    public function testMissingRequestUriDoesNotThrowTypeError(): void
    {
        // Previously str_starts_with(null, ...) threw a TypeError under strict_types.
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.5', 'REQUEST_METHOD' => 'GET']);

        maybeRateLimit($request);

        self::assertCount(1, WpStub::$transients);
    }
}
