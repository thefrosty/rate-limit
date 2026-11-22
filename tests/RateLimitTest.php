<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WpDieException;
use WpSendJsonException;
use WpStub;
use function TheFrosty\RateLimit\maybeRateLimit;
use const TheFrosty\RateLimit\MAX_REQUESTS_PER_MINUTE;

final class RateLimitTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.5';

    protected function setUp(): void
    {
        WpStub::reset();
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $parameters
     */
    private static function request(
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

    private static function transientKey(string $ip): string
    {
        return sprintf('_thefrosty_rate_limit_%s', md5($ip));
    }

    /**
     * Send requests until one is rate limited; returns how many were allowed through.
     * @param callable(): Request $factory
     */
    private static function allowedBeforeLimit(callable $factory, int $max = 100): int
    {
        for ($i = 0; $i < $max; $i++) {
            try {
                maybeRateLimit($factory());
            } catch (WpSendJsonException | WpDieException) {
                return $i;
            }
        }
        return $max;
    }

    public function testFirstRequestStartsAWindow(): void
    {
        maybeRateLimit(self::request());

        $data = WpStub::$transients[self::transientKey(self::CLIENT_IP)] ?? null;
        self::assertIsObject($data);
        self::assertSame(1, $data->limits[self::CLIENT_IP]['count']);
        self::assertEqualsWithDelta(time(), $data->limits[self::CLIENT_IP]['start_time'], 2);
    }

    public function testRequestsOverTheLimitGetAJson429(): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request());

        self::assertSame(MAX_REQUESTS_PER_MINUTE, $allowed);

        try {
            maybeRateLimit(self::request());
            self::fail('Expected a 429 response.');
        } catch (WpSendJsonException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame(['error' => 'Too Many Requests.'], $exception->response);
        }
    }

    public function testHtmlClientsGetWpDie429(): void
    {
        $factory = static fn(): Request => self::request('/', 'GET', ['HTTP_ACCEPT' => 'text/html']);
        for ($i = 0; $i < MAX_REQUESTS_PER_MINUTE; $i++) {
            maybeRateLimit($factory());
        }

        $this->expectException(WpDieException::class);
        $this->expectExceptionCode(429);
        maybeRateLimit($factory());
    }

    public function testExpiredWindowResetsTheCount(): void
    {
        $data = new \stdClass();
        $data->limits[self::CLIENT_IP] = ['count' => 500, 'start_time' => time() - MINUTE_IN_SECONDS - 1];
        WpStub::$transients[self::transientKey(self::CLIENT_IP)] = $data;

        maybeRateLimit(self::request());

        $stored = WpStub::$transients[self::transientKey(self::CLIENT_IP)];
        self::assertSame(1, $stored->limits[self::CLIENT_IP]['count']);
    }

    public function testClientsHaveSeparateBuckets(): void
    {
        self::allowedBeforeLimit(static fn(): Request => self::request());

        // A different client is unaffected by the first client being limited.
        maybeRateLimit(self::request(server: ['REMOTE_ADDR' => '198.51.100.7']));
        self::assertArrayHasKey(self::transientKey('198.51.100.7'), WpStub::$transients);
    }

    public function testIpv6AddressesThatSanitizeAlikeHaveSeparateBuckets(): void
    {
        // sanitize_key() reduces both of these to "123".
        self::allowedBeforeLimit(static fn(): Request => self::request(server: ['REMOTE_ADDR' => '1:23::']));

        maybeRateLimit(self::request(server: ['REMOTE_ADDR' => '12:3::']));
        self::assertCount(2, WpStub::$transients);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neverLimitedIps(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
    }

    #[DataProvider('neverLimitedIps')]
    public function testLoopbackIsNeverLimited(string $ip): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request(server: ['REMOTE_ADDR' => $ip]), 50);

        self::assertSame(50, $allowed);
        self::assertSame([], WpStub::$transients);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skippedMethods(): iterable
    {
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[DataProvider('skippedMethods')]
    public function testHeadAndOptionsAreNotCounted(string $method): void
    {
        maybeRateLimit(self::request(method: $method));

        self::assertSame([], WpStub::$transients);
    }

    public function testAdministratorsAreNotLimited(): void
    {
        WpStub::$loggedIn = true;
        WpStub::$caps = ['manage_options'];

        self::assertSame(50, self::allowedBeforeLimit(static fn(): Request => self::request(), 50));
        self::assertSame([], WpStub::$transients);
    }

    public function testLoggedInNonAdministratorsAreLimited(): void
    {
        WpStub::$loggedIn = true;
        WpStub::$caps = ['read'];

        self::assertLessThan(50, self::allowedBeforeLimit(static fn(): Request => self::request(), 50));
    }

    public function testSiteHealthEndpointIsNotLimited(): void
    {
        $factory = static fn(): Request => self::request('/wp-json/wp-site-health/v1/tests/loopback-requests');

        self::assertSame(50, self::allowedBeforeLimit($factory, 50));
        self::assertSame([], WpStub::$transients);
    }
}
