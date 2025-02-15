<?php

namespace Jasny\HttpDigest\Tests;

use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\HttpDigestException;
use Jasny\HttpDigest\ServerMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \Jasny\HttpDigest\ServerMiddleware
 */
class ServerMiddlewareTest extends TestCase
{
    use TestHelper;
    use Traits\MethodProviderTrait;
    use Traits\MockRequestTrait;

    /**
     * @var HttpDigest&MockObject
     */
    protected $service;

    /**
     * @var ResponseFactoryInterface&MockObject
     */
    protected $responseFactory;

    /**
     * @var ServerMiddleware
     */
    protected $middleware;


    public function setUp(): void
    {
        $this->service = $this->createMock(HttpDigest::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);

        $this->middleware = new ServerMiddleware($this->service, $this->responseFactory);
    }


    /**
     * @dataProvider anyMethodProvider
     */
    public function testProcessWithValidDigest(string $method)
    {
        $request = $this->createMockRequest($method, 'test');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $response = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')
            ->with($this->identicalTo($request))
            ->willReturn($response);

        $this->service->expects($this->once())->method('verify')
            ->with('test', 'MD5=CY9rzUYh03PK3k6DJie09g==');

        $ret = $this->middleware->process($request, $handler);

        $this->assertSame($response, $ret);
    }

    /**
     * @dataProvider noDigestMethodProvider
     */
    public function testProcessWithoutDigest(string $method)
    {
        $request = $this->createMockRequest($method);
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(false);
        $request->expects($this->never())->method('getHeaderLine');

        $response = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')
            ->with($this->identicalTo($request))
            ->willReturn($response);

        $this->service->expects($this->never())->method('verify');

        $ret = $this->middleware->process($request, $handler);

        $this->assertSame($response, $ret);
    }

    public function testProcessWithInvalidDigest()
    {
        $request = $this->createMockRequest('POST', 'hello');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $badResponseBody = $this->createMock(StreamInterface::class);
        $badResponseBody->expects($this->once())->method('write')->with('invalid digest');

        $badResponse = $this->createMock(ResponseInterface::class);
        $badResponse->expects($this->exactly(2))->method('withHeader')
            ->withConsecutive(
                ['Want-Digest', 'MD5;q=0.3, SHA;q=0.5, SHA-256'],
                ['Content-Type', 'text/plain']
            )
            ->willReturnSelf();
        $badResponse->expects($this->once())->method('getBody')->willReturn($badResponseBody);

        $this->responseFactory->expects($this->once())->method('createResponse')
            ->with(400)->willReturn($badResponse);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->service->expects($this->once())->method('verify')
            ->with('hello', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willThrowException(new HttpDigestException('invalid digest'));
        $this->service->expects($this->once())->method('getWantDigest')
            ->willReturn('MD5;q=0.3, SHA;q=0.5, SHA-256');

        $ret = $this->middleware->process($request, $handler);

        $this->assertSame($badResponse, $ret);
    }

    /**
     * @dataProvider alwaysDigestMethodProvider
     */
    public function testProcessWithMissingDigest(string $method)
    {
        $request = $this->createMockRequest($method);
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(false);
        $request->expects($this->never())->method('getHeaderLine');

        $badResponseBody = $this->createMock(StreamInterface::class);
        $badResponseBody->expects($this->once())->method('write')->with('digest header missing');

        $badResponse = $this->createMock(ResponseInterface::class);
        $badResponse->expects($this->exactly(2))->method('withHeader')
            ->withConsecutive(
                ['Want-Digest', 'MD5;q=0.3, SHA;q=0.5, SHA-256'],
                ['Content-Type', 'text/plain']
            )
            ->willReturnSelf();
        $badResponse->expects($this->once())->method('getBody')->willReturn($badResponseBody);

        $this->responseFactory->expects($this->once())->method('createResponse')
            ->with(400)->willReturn($badResponse);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->service->expects($this->never())->method('verify');
        $this->service->expects($this->once())->method('getWantDigest')
            ->willReturn('MD5;q=0.3, SHA;q=0.5, SHA-256');

        $ret = $this->middleware->process($request, $handler);

        $this->assertSame($badResponse, $ret);
    }

    /**
     * @dataProvider alwaysDigestMethodProvider
     */
    public function testProcessWithOptionalDigest(string $method)
    {
        $this->middleware = $this->middleware->withOptionalDigest();

        $request = $this->createMockRequest($method);
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(false);
        $request->expects($this->never())->method('getHeaderLine');

        $response = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')
            ->with($this->identicalTo($request))
            ->willReturn($response);

        $this->service->expects($this->never())->method('verify');

        $ret = $this->middleware->process($request, $handler);

        $this->assertSame($response, $ret);
    }

    public function testProcessWithoutResponseFactory()
    {
        $request = $this->createMockRequest('POST', 'hello');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $this->service->expects($this->once())->method('verify')
            ->with('hello', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willThrowException(new HttpDigestException('invalid digest'));

        $middleware = new ServerMiddleware($this->service); // No response factory

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage("Response factory not set");

        $middleware->process($request, $handler);
    }


    /**
     * @dataProvider anyMethodProvider
     */
    public function testAsDoublePassMiddleware(string $method)
    {
        $request = $this->createMockRequest($method, 'test');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $response = $this->createMock(ResponseInterface::class);

        $next = $this->createCallbackMock(
            $this->once(),
            [$this->identicalTo($request), $this->identicalTo($response)],
            $response
        );

        $this->service->expects($this->once())->method('verify')
            ->with('test', 'MD5=CY9rzUYh03PK3k6DJie09g==');

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($response, $ret);
    }

    public function testAsDoublePassMiddlewareWithInvalidDigestWithoutFactory()
    {
        $this->middleware = new ServerMiddleware($this->service);
        $this->responseFactory->expects($this->never())->method('createResponse');

        $request = $this->createMockRequest('POST', 'hello');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $badResponseBody = $this->createMock(StreamInterface::class);
        $badResponseBody->expects($this->once())->method('write')->with('invalid digest');

        $badResponse = $this->createMock(ResponseInterface::class);
        $badResponse->expects($this->exactly(2))->method('withHeader')
            ->withConsecutive(
                ['Want-Digest', 'MD5;q=0.3, SHA;q=0.5, SHA-256'],
                ['Content-Type', 'text/plain']
            )
            ->willReturnSelf();
        $badResponse->expects($this->once())->method('getBody')->willReturn($badResponseBody);

        $responseBody = $this->createMock(StreamInterface::class);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $response->expects($this->any())->method('getBody')->willReturn($responseBody);
        $response->expects($this->once())->method('withBody')->willReturn($badResponse);

        $next = $this->createCallbackMock($this->never());

        $this->service->expects($this->once())->method('verify')
            ->with('hello', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willThrowException(new HttpDigestException('invalid digest'));
        $this->service->expects($this->once())->method('getWantDigest')
            ->willReturn('MD5;q=0.3, SHA;q=0.5, SHA-256');

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($badResponse, $ret);
    }

    public function testAsDoublePassMiddlewareWithInvalidDigestWithFactory()
    {
        $request = $this->createMockRequest('POST', 'hello');
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(true);
        $request->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Digest')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $badResponseBody = $this->createMock(StreamInterface::class);
        $badResponseBody->expects($this->once())->method('write')->with('invalid digest');

        $badResponse = $this->createMock(ResponseInterface::class);
        $badResponse->expects($this->exactly(2))->method('withHeader')
            ->withConsecutive(
                ['Want-Digest', 'MD5;q=0.3, SHA;q=0.5, SHA-256'],
                ['Content-Type', 'text/plain']
            )
            ->willReturnSelf();
        $badResponse->expects($this->once())->method('getBody')->willReturn($badResponseBody);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->never())->method('withStatus');
        $response->expects($this->never())->method('withBody');

        $this->responseFactory->expects($this->once())->method('createResponse')
            ->with(400)->willReturn($badResponse);

        $next = $this->createCallbackMock($this->never());

        $this->service->expects($this->once())->method('verify')
            ->with('hello', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willThrowException(new HttpDigestException('invalid digest'));
        $this->service->expects($this->once())->method('getWantDigest')
            ->willReturn('MD5;q=0.3, SHA;q=0.5, SHA-256');

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($badResponse, $ret);
    }

    /**
     * @dataProvider alwaysDigestMethodProvider
     */
    public function testAsDoublePassWithMissingDigest(string $method)
    {
        $this->middleware = new ServerMiddleware($this->service);
        $this->responseFactory->expects($this->never())->method('createResponse');

        $request = $this->createMockRequest($method);
        $request->expects($this->any())->method('hasHeader')->with('Digest')->willReturn(false);
        $request->expects($this->never())->method('getHeaderLine');

        $badResponseBody = $this->createMock(StreamInterface::class);
        $badResponseBody->expects($this->once())->method('write')->with('digest header missing');

        $badResponse = $this->createMock(ResponseInterface::class);
        $badResponse->expects($this->exactly(2))->method('withHeader')
            ->withConsecutive(
                ['Want-Digest', 'MD5;q=0.3, SHA;q=0.5, SHA-256'],
                ['Content-Type', 'text/plain']
            )
            ->willReturnSelf();
        $badResponse->expects($this->once())->method('getBody')->willReturn($badResponseBody);

        $responseBody = $this->createMock(StreamInterface::class);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $response->expects($this->any())->method('getBody')->willReturn($responseBody);
        $response->expects($this->once())->method('withBody')->willReturn($badResponse);

        $next = $this->createCallbackMock($this->never());

        $this->service->expects($this->never())->method('verify');
        $this->service->expects($this->once())->method('getWantDigest')
            ->willReturn('MD5;q=0.3, SHA;q=0.5, SHA-256');

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($badResponse, $ret);
    }

    public function testWithOptional()
    {
        $optionalMiddleware = $this->middleware->withOptionalDigest();
        $this->assertNotSame($optionalMiddleware, $this->middleware->withOptionalDigest());

        $this->assertSame($optionalMiddleware, $optionalMiddleware->withOptionalDigest());

        $this->assertNotSame($optionalMiddleware, $optionalMiddleware->withOptionalDigest(false));
    }
}
