<?php declare(strict_types=1);

namespace Jasny\HttpDigest;

use Http\Client\Common\Plugin as HttpPlugin;
use Http\Promise\Promise as HttpPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Middleware to sign PSR-7 HTTP requests.
 */
class ClientMiddleware
{
    /**
     * @var HttpDigest
     */
    protected $service;

    /**
     * Class constructor.
     *
     * @param HttpDigest $service
     */
    public function __construct(HttpDigest $service)
    {
        $this->service = $service;
    }


    /**
     * Return a callback that can be used as double pass middleware.
     *
     * @return callable
     */
    public function asDoublePass(): callable
    {
        return function (RequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface {
            $nextRequest = $this->shouldHaveDigest($request) ? $this->addDigest($request) : $request;
            return $next($nextRequest, $response);
        };
    }

    /**
     * Return a callback that can be used as Guzzle middleware.
     * @see http://docs.guzzlephp.org/en/stable/handlers-and-middleware.html
     *
     * @return callable
     */
    public function forGuzzle(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $nextRequest = $this->shouldHaveDigest($request) ? $this->addDigest($request) : $request;
                return $handler($nextRequest, $options);
            };
        };
    }

    /**
     * Create a version of this middleware that can be used in HTTPlug.
     * @see http://docs.php-http.org/en/latest/plugins/introduction.html
     *
     * @return self&HttpPlugin
     */
    public function forHttplug(): HttpPlugin
    {
        return new class ($this->service) extends ClientMiddleware implements HttpPlugin {
            public function handleRequest(RequestInterface $request, callable $next, callable $first): HttpPromise
            {
                $nextRequest = $this->shouldHaveDigest($request) ? $this->addDigest($request) : $request;
                return $next($nextRequest);
            }
        };
    }


    /**
     * Check if a digest header should be added to the request.
     *
     * @param RequestInterface $request
     * @return bool
     */
    protected function shouldHaveDigest(RequestInterface $request)
    {
        switch (strtoupper($request->getMethod())) {
            case 'GET':
            case 'HEAD':
            case 'OPTIONS':
                return false;

            case 'PATCH':
            case 'POST':
            case 'PUT':
                return true;

            default:
                return $request->getBody()->getSize() > 0;
        }
    }

    /**
     * Add a digest header to the request.
     *
     * @param RequestInterface $request
     * @return RequestInterface
     * @throws \RuntimeException if unable to read or an error occurs while reading
     */
    protected function addDigest(RequestInterface $request): RequestInterface
    {
        $digest = $this->service->create($request->getBody()->getContents());

        return $request->withHeader('Digest', $digest);
    }
}
