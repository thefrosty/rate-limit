# Rate Limiter

[![PHP from Packagist](https://img.shields.io/packagist/php-v/thefrosty/rate-limit.svg)]()
[![Latest Stable Version](https://img.shields.io/packagist/v/thefrosty/rate-limit.svg)](https://packagist.org/packages/thefrosty/rate-limit)
[![Total Downloads](https://img.shields.io/packagist/dt/thefrosty/rate-limit.svg)](https://packagist.org/packages/thefrosty/rate-limit)
[![License](https://img.shields.io/packagist/l/thefrosty/rate-limit.svg)](https://packagist.org/thefrosty/rate-limit)

Rate limit API requests with a HTTP Response 429, "Too Many Requests".

## Client IP & proxies

The limiter keys on `REMOTE_ADDR` and ignores `Client-IP`, `CF-Connecting-IP` and `X-Forwarded-For`, because any client
can send those headers. If the site sits behind a load balancer or CDN, register its addresses so `X-Forwarded-For` is
honoured when the request really came through that proxy:

```php
add_filter('thefrosty/rate_limit/trusted_proxies', static fn(): array => ['10.0.0.0/8', '173.245.48.0/20']);
```

Without this, every visitor behind the proxy shares one bucket, unless the web server already rewrites `REMOTE_ADDR`
(e.g. nginx `real_ip`, Apache `mod_remoteip`).
