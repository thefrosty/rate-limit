<?php

declare(strict_types=1);

namespace TheFrosty\RateLimit\Tests;

use Symfony\Component\HttpFoundation\Request;
use function TheFrosty\RateLimit\getClientIp;
use const TheFrosty\RateLimit\MAX_REQUESTS_PER_MINUTE;

final class ClientIpTest extends TestCase
{
    public function tear_down(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tear_down();
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

    /**
     * @dataProvider spoofedHeaders
     */
    public function test_client_supplied_headers_cannot_bypass_the_limit(string $header, string $value): void
    {
        $allowed = self::allowedBeforeLimit(static fn(): Request => self::request(server: [$header => $value]));

        self::assertSame(MAX_REQUESTS_PER_MINUTE, $allowed);
    }

    public function test_rotating_forwarded_for_stays_in_one_bucket(): void
    {
        $i = 0;
        $factory = static function () use (&$i): Request {
            $i++;
            return self::request(server: ['HTTP_X_FORWARDED_FOR' => "198.51.100.$i"]);
        };

        self::assertSame(MAX_REQUESTS_PER_MINUTE, self::allowedBeforeLimit($factory));
        self::assertNotFalse(get_transient(self::transientKey(self::CLIENT_IP)));
        self::assertFalse(get_transient(self::transientKey('198.51.100.1')));
    }

    public function test_forwarded_for_is_honoured_from_a_trusted_proxy(): void
    {
        add_filter('thefrosty/rate_limit/trusted_proxies', static fn(): array => ['10.0.0.0/8']);

        $request = self::request(server: ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9']);

        self::assertSame('198.51.100.9', getClientIp($request));
    }

    public function test_forwarded_for_is_ignored_from_an_untrusted_peer(): void
    {
        add_filter('thefrosty/rate_limit/trusted_proxies', static fn(): array => ['10.0.0.0/8']);

        $request = self::request(server: ['HTTP_X_FORWARDED_FOR' => '198.51.100.9']);

        self::assertSame(self::CLIENT_IP, getClientIp($request));
    }

    public function test_invalid_remote_addr_resolves_to_null(): void
    {
        self::assertNull(getClientIp(self::request(server: ['REMOTE_ADDR' => 'garbage'])));
    }
}
