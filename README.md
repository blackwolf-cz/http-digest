Jasny HTTP Digest
===

[![Build Status](https://travis-ci.org/jasny/http-digest.svg?branch=master)](https://travis-ci.org/jasny/http-digest)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/jasny/http-digest/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/jasny/http-digest/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/jasny/http-digest/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/jasny/http-digest/?branch=master)
[![Packagist Stable Version](https://img.shields.io/packagist/v/jasny/http-digest.svg)](https://packagist.org/packages/jasny/http-digest)
[![Packagist License](https://img.shields.io/packagist/l/jasny/http-digest.svg)](https://packagist.org/packages/jasny/http-digest)

PSR-7 client and server middleware for HTTP Digest header creation and validation as described in
[RFC 3230](https://tools.ietf.org/html/rfc3230). Supports MD5, SHA, SHA-256 and SHA-512
([RFC 5843](https://tools.ietf.org/html/rfc5843)).

The `Digest` header contains a hash of the body.

    Digest: SHA=thvDyvhfIqlvFe+A9MYgxAfm1q5=

The Want-Digest message header field indicates the sender's desire to receive an instance digest on messages associated
with the Request-URI.

    Want-Digest: MD5;q=0.3, SHA;q=1

Installation
---

    composer require jasny/http-digest

Usage
---

Create the `HttpDigest` service to create and verify digests. Give the server priorities for supported algorithms. This
value should be similar to those in the `Want-Digest` header.

```php
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
```

The priorities may also be specified as string.

```php
$service = HttpDigest(new DigestNegotiator(), "MD5;q=0.3, SHA;q=1");
```

### Creating a digest

You can use the service to create a digest for content.

```php
$digest = $service->create($body);
```

### Verifying a digest

You can use the service to verify the digest.

```php
$service->verify($body, $digest);
```

If the digest doesn't match or if the algorithm is unsupported, a `HttpDigestException` is thrown.

### Priorities and the `Want-Digest` header

You can change the priorities using `withPriorities()`. This will create a new copy of the service.

```php
$newService = $service->withPriorities(["MD5;q=0.3", "SHA;q=0.5", "SHA-256;q=1"]);
```

To get the configured priorities use `getPriorities()`. The `getWantDigest()` function returns the priorities in as a
string in the format expected for `Wanted-Digest`.

```php
$priorities = $service->getPriorities();
$header = $service->getWantDigest();
```

### Server middleware

Server middleware can be used to verify the digest of PSR-7 requests.

When the middleware is used, requests with a body (like `POST` or `GET` requests) must contain a `Digest` header.
If the `Digest` header is missing, invalid or doesn't meet the requirements, the middleware will return a
`400 Bad Request` response with a `With-Digest` header and the handler will not be called.

#### Single pass middleware (PSR-15)

The middleware implements the PSR-15 `MiddlewareInterface`. As PSR standard many new libraries support this type of
middleware, for example [Zend Stratigility](https://docs.zendframework.com/zend-stratigility/). 

You're required to supply a [PSR-17 response factory](https://www.php-fig.org/psr/psr-17/#22-responsefactoryinterface),
to create a `400 Bad Request` response for requests with invalid signatures.

```php
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\ServerMiddleware;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Zend\Stratigility\MiddlewarePipe;
use Zend\Diactoros\ResponseFactory;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
$responseFactory = new ResponseFactory();
$middleware = new ServerMiddleware($service, $responseFactory);

$app = new MiddlewarePipe();
$app->pipe($middleware);
```

#### Double pass middleware

Many PHP libraries support double pass middleware. These are callables with the following signature;

```php
fn(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
```

To get a callback to be used by libraries as [Jasny Router](https://github.com/jasny/router) and
[Relay](http://relayphp.com/), use the `asDoublePass()` method.

```php
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\ServerMiddleware;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Relay\RelayBuilder;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
$middleware = new ServerMiddleware($service);

$relayBuilder = new RelayBuilder($resolver);
$relay = $relayBuilder->newInstance([
    $middleware->asDoublePass(),
]);

$response = $relay($request, $baseResponse);
```

### Client middleware

Client middleware can be used to sign requests send by PSR-7 compatible HTTP clients like
[Guzzle](http://docs.guzzlephp.org) and [HTTPlug](http://docs.php-http.org).

```php
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\ClientMiddleware;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
$middleware = new ClientMiddleware($service);
```

#### Double pass middleware

The client middleware can be used by any client that does support double pass middleware. Such middleware are callables
with the following signature;

```php
fn(RequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
```

Most HTTP clients do not support double pass middleware, but a type of single pass instead. However more general
purpose PSR-7 middleware libraries, like [Relay](http://relayphp.com/), do support double pass.

```php
use Relay\RelayBuilder;

$relayBuilder = new RelayBuilder($resolver);
$relay = $relayBuilder->newInstance([
    $middleware->asDoublePass(),
]);

$response = $relay($request, $baseResponse);
```

_The client middleware does not conform to PSR-15 (single pass) as that is intended for server requests only._

#### Guzzle

[Guzzle](http://docs.guzzlephp.org) is the most popular HTTP Client for PHP. The middleware has a `forGuzzle()` method
that creates a callback which can be used as Guzzle middleware.

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\ClientMiddleware;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
$middleware = new ClientMiddleware($service);

$stack = new HandlerStack();
$stack->push($middleware->forGuzzle());

$client = new Client(['handler' => $stack]);
```

#### HTTPlug

[HTTPlug](http://docs.php-http.org/en/latest/httplug/introduction.html) is the HTTP client of PHP-HTTP. It allows you
to write reusable libraries and applications that need an HTTP client without binding to a specific implementation.

The `forHttplug()` method for the middleware creates an object that can be used as HTTPlug plugin.

```php
use Http\Discovery\HttpClientDiscovery;
use Http\Client\Common\PluginClient;
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\ClientMiddleware;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;

$service = HttpDigest(new DigestNegotiator(), ["MD5;q=0.3", "SHA;q=1"]);
$middleware = new ClientMiddleware($service);

$pluginClient = new PluginClient(
    HttpClientDiscovery::find(),
    [
        $middleware->forHttplug(),
    ]
);
```

