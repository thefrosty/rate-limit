<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WpSendJsonException;
use WpStub;
use function TheFrosty\RateLimit\getClientIp;
use function TheFrosty\RateLimit\maybeRateLimit;
use const TheFrosty\RateLimit\MAX_REQUESTS_PER_MINUTE;

final class ClientIpTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.5';

    protected function setUp(): void
    {
        WpStub::reset();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function request(array $server = []): Request
    {
        return Request::create(
            '/wp-json/wp/v2/posts',
            'GET',
            [],
            [],
            [],
            $server + ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_ACCEPT' => 'application/json']
        );
    }

    /**
     * @param callable(): Request $factory
     */
    private static function allowedBeforeLimit(callable $factory, int $max = 50): int
    {
        for ($i = 0; $i < $max; $i++) {
            try {
                maybeRateLimit($factory());
            } catch (WpSendJsonException) {
                return $i;
            }
        }
        return $max;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spoofedHeaders(): iterable
    {
        yield 'Client-IP loopback' => ['HTTP_CLIENT_IP', '127.0.0.1'];
        yield 'CF-Connecting-IP loopback' => ['HTTP_CF_CONNECTING_IP', '127.0.0.1'];
        yield 'X-Forwarded-For loopback' => ['HTTP_X_FORWARDED_FOR', '::1'];
        yield 'X-Forwarded loopback' => ['HTTP_X_FORWARDED', '127.0.0.1'];
        yield 'Client-IP garbage' => ['HTTP_CLIENT_IP', 'not-an-ip'];
        yield 'X-Forwarded-For list' => ['HTTP_X_FORWARDED_FOR', '1.1.1.1, 2.2.2.2'];
    }

    #[DataProvider('spoofedHeaders')]
    public function testClientSuppliedHeadersCannotBypassTheLimit(string $header, string $value): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request([$header => $value]));

        self::assertLessThanOrEqual(MAX_REQUESTS_PER_MINUTE + 1, $allowed);
    }

    public function testRotatingForwardedForStaysInOneBucket(): void
    {
        $i = 0;
        $factory = static function () use (&$i): Request {
            $i++;
            return self::request(['HTTP_X_FORWARDED_FOR' => "198.51.100.$i"]);
        };

        self::assertLessThanOrEqual(MAX_REQUESTS_PER_MINUTE + 1, self::allowedBeforeLimit($factory));
        self::assertCount(1, WpStub::$transients);
    }

    public function testForwardedForIsHonouredFromATrustedProxy(): void
    {
        WpStub::$filters['thefrosty/rate_limit/trusted_proxies'] = static fn(): array => ['10.0.0.0/8'];

        $request = self::request(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9']);

        self::assertSame('198.51.100.9', getClientIp($request));
    }

    public function testForwardedForIsIgnoredFromAnUntrustedPeer(): void
    {
        WpStub::$filters['thefrosty/rate_limit/trusted_proxies'] = static fn(): array => ['10.0.0.0/8'];

        $request = self::request(['HTTP_X_FORWARDED_FOR' => '198.51.100.9']);

        self::assertSame(self::CLIENT_IP, getClientIp($request));
    }

    public function testInvalidRemoteAddrResolvesToNull(): void
    {
        self::assertNull(getClientIp(self::request(['REMOTE_ADDR' => 'garbage'])));
    }
}
