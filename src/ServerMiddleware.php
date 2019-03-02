<?php declare(strict_types=1);

namespace Jasny\HttpDigest;

use Psr\Http\Message\ServerRequestInterface as ServerRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware to verify HTTP Digest header.
 * Can be used both as single pass (PSR-15) and double pass middleware.
 */
class ServerMiddleware implements MiddlewareInterface
{
    /**
     * @var HttpDigest
     */
    protected $service;

    /**
     * @var ResponseFactory|null
     */
    protected $responseFactory;

    /**
     * Class constructor.
     *
     * @param HttpDigest        $service
     * @param ResponseFactory|null $responseFactory
     */
    public function __construct(HttpDigest $service, ?ResponseFactory $responseFactory = null)
    {
        $this->service = $service;
        $this->responseFactory = $responseFactory;
    }


    /**
     * Process an incoming server request (PSR-15).
     *
     * @param ServerRequest  $request
     * @param RequestHandler $handler
     * @return Response
     * @throws \RuntimeException if unauthorized response can't be created
     */
    public function process(ServerRequest $request, RequestHandler $handler): Response
    {
        $next = function (ServerRequest $request) use ($handler) {
            return $handler->handle($request);
        };

        return $request->hasHeader('Digest')
            ? $this->handleDigestRequest($request, null, $next)
            : $this->handleNoDigestRequest($request, null, $next);
    }

    /**
     * Get a callback that can be used as double pass middleware.
     *
     * @return callable
     */
    public function asDoublePass(): callable
    {
        return function (ServerRequest $request, Response $response, callable $next): Response {
            return $request->hasHeader('Digest')
                ? $this->handleDigestRequest($request, $response, $next)
                : $this->handleNoDigestRequest($request, $response, $next);
        };
    }


    /**
     * Check if the should have a digest header.
     *
     * @param ServerRequest $request
     * @return bool
     */
    protected function shouldHaveDigest(ServerRequest $request)
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
     * Handle request with a Digest header.
     *
     * @param ServerRequest  $request
     * @param Response|null  $response
     * @param callable       $next
     * @return Response
     * @throws \RuntimeException when the bad request response can't be created.
     */
    protected function handleDigestRequest(ServerRequest $request, ?Response $response, callable $next): Response
    {
        try {
            $this->service->verify($request->getBody()->getContents(), $request->getHeaderLine('Digest'));
        } catch (HttpDigestException $exception) {
            return $this->createBadRequestResponse($response, $exception->getMessage());
        }

        return $next($request, $response);
    }

    /**
     * Handle request without a Digest header.
     *
     * @param ServerRequest  $request
     * @param Response|null  $response
     * @param callable       $next
     * @return Response
     * @throws \RuntimeException when the bad request response can't be created.
     */
    protected function handleNoDigestRequest(ServerRequest $request, ?Response $response, callable $next): Response
    {
        if ($this->shouldHaveDigest($request)) {
            return $this->createBadRequestResponse($response, 'Digest header missing');
        }

        return $next($request, $response);
    }

    /**
     * Create a response using the response factory.
     *
     * @param int $status  Response status
     * @return Response
     */
    protected function createResponse(int $status): Response
    {
        if ($this->responseFactory === null) {
            throw new \BadMethodCallException('Response factory not set');
        }

        return $this->responseFactory->createResponse($status);
    }

    /**
     * Create a `401 Unauthorized` response.
     *
     * @param ServerRequest $request
     * @param Response|null $response
     * @param string        $message
     * @return Response
     * @throws \RuntimeException when can't write body.
     */
    protected function createBadRequestResponse(?Response $response, string $message): Response {
        $newResponse = $response === null
            ? $this->createResponse(400)
            : $response->withStatus(400)->withBody(clone $response->getBody());

        $errorResponse = $newResponse
            ->withHeader('Want-Digest', $this->service->getWantDigest())
            ->withHeader('Content-Type', 'text/plain');

        $errorResponse->getBody()->write($message);

        return $errorResponse;
    }
}
