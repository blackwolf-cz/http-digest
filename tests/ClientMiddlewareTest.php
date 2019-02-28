<?php

namespace Jasny\HttpDigest\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack as GuzzleHandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Http\Mock\Client as HttpMockClient;
use Http\Client\Common\PluginClient as HttpPluginClient;
use Jasny\HttpDigest\ClientMiddleware;
use Jasny\HttpDigest\HttpDigest;
use Jasny\TestHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \Jasny\HttpDigest\ClientMiddleware
 */
class ClientMiddlewareTest extends TestCase
{
    use TestHelper;

    /**
     * @var HttpDigest&MockObject
     */
    protected $service;

    /**
     * @var ClientMiddleware
     */
    protected $middleware;


    public function setUp()
    {
        $this->service = $this->createMock(HttpDigest::class);
        $this->middleware = new ClientMiddleware($this->service);
    }

    /**
     * Create a mock digest request.
     *
     * @param string $method
     * @param string $contents
     * @return RequestInterface&MockObject
     */
    protected function createMockRequest(string $method, ?string $contents = null): RequestInterface
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects($this->any())->method('getContents')->willReturn($contents);
        $body->expects($this->any())->method('getSize')->willReturn(strlen($contents));

        $request = $this->createMock(RequestInterface::class);
        $request->expects($this->any())->method('getMethod')->willReturn($method);
        $request->expects($this->any())->method('getBody')->willReturn($body);

        return $request;
    }


    public function digestMethodProvider()
    {
        return [
            ['POST'],
            ['PUT'],
            ['PATCH'],
            ['DELETE'],
            ['CUSTOM'],
        ];
    }


    public function noDigestMethodProvider()
    {
        return [
            ['GET'],
            ['HEAD'],
            ['DELETE'],
            ['CUSTOM'],
        ];
    }

    /**
     * @dataProvider digestMethodProvider
     */
    public function testAsDoublePassMiddleware(string $method)
    {
        $digestRequest = $this->createMock(RequestInterface::class);

        $request = $this->createMockRequest($method, 'test');
        $request->expects($this->once())->method('withHeader')
            ->with('Digest', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willReturn($digestRequest);

        $response = $this->createMock(ResponseInterface::class);

        $this->service->expects($this->once())->method('create')
            ->with('test')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $next = $this->createCallbackMock($this->once(), [$this->identicalTo($digestRequest)], $response);

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($response, $ret);
    }

    /**
     * @dataProvider noDigestMethodProvider
     */
    public function testAsDoublePassMiddlewareWithoutDigest(string $method)
    {
        $request = $this->createMockRequest($method);
        $request->expects($this->never())->method('withHeader');

        $response = $this->createMock(ResponseInterface::class);

        $this->service->expects($this->never())->method('create');

        $next = $this->createCallbackMock($this->once(), [$this->identicalTo($request)], $response);

        $doublePass = $this->middleware->asDoublePass();
        $ret = $doublePass($request, $response, $next);

        $this->assertSame($response, $ret);
    }


    /**
     * @dataProvider digestMethodProvider
     */
    public function testAsGuzzleMiddlewareWithSyncRequest(string $method)
    {
        $response = $this->createMock(ResponseInterface::class);
        $history = [];

        $this->service->expects($this->once())->method('create')
            ->with('test')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $mockHandler = new GuzzleMockHandler([$response]);
        $handlerStack = GuzzleHandlerStack::create($mockHandler);

        $handlerStack->push($this->middleware->forGuzzle());
        $handlerStack->push(GuzzleMiddleware::history($history));

        $client = new GuzzleClient(['handler' => $handlerStack]);

        $options = ['timeout' => 90, 'answer' => 42, 'body' => 'test'];

        $ret = $client->request($method, '/foo', $options);

        $this->assertSame($response, $ret);

        $this->assertCount(1, $history);
        $this->assertInstanceOf(GuzzleRequest::class, $history[0]['request']);
        $this->assertEquals('MD5=CY9rzUYh03PK3k6DJie09g==', $history[0]['request']->getHeaderLine('Digest'));
        $this->assertSame($response, $history[0]['response']);

        $expectedOptions = ['timeout' => 90, 'answer' => 42, 'handler' => $handlerStack];
        $actualOptions = array_intersect_key($history[0]['options'], $expectedOptions);
        $this->assertSame($expectedOptions, $actualOptions);
    }

    /**
     * @dataProvider digestMethodProvider
     */
    public function testAsGuzzleMiddlewareWithAsyncRequest(string $method)
    {
        $response = $this->createMock(ResponseInterface::class);
        $history = [];

        $this->service->expects($this->once())->method('create')
            ->with('test')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $mockHandler = new GuzzleMockHandler([$response]);
        $handlerStack = GuzzleHandlerStack::create($mockHandler);

        $handlerStack->push($this->middleware->forGuzzle());
        $handlerStack->push(GuzzleMiddleware::history($history));

        $client = new GuzzleClient(['handler' => $handlerStack]);

        $options = ['timeout' => 90, 'answer' => 42, 'body' => 'test'];

        $ret = $client->requestAsync($method, '/foo', $options);

        $this->assertInstanceOf(GuzzlePromise::class, $ret);
        $this->assertSame($response, $ret->wait());

        $this->assertCount(1, $history);
        $this->assertInstanceOf(GuzzleRequest::class, $history[0]['request']);
        $this->assertEquals('MD5=CY9rzUYh03PK3k6DJie09g==', $history[0]['request']->getHeaderLine('Digest'));
        $this->assertSame($response, $history[0]['response']);

        $expectedOptions = ['timeout' => 90, 'answer' => 42, 'handler' => $handlerStack];
        $actualOptions = array_intersect_key($history[0]['options'], $expectedOptions);
        $this->assertSame($expectedOptions, $actualOptions);
    }

    /**
     * @dataProvider noDigestMethodProvider
     */
    public function testAsGuzzleMiddlewareWithoutDigest(string $method)
    {
        $response = $this->createMock(ResponseInterface::class);
        $history = [];

        $this->service->expects($this->never())->method('create');

        $mockHandler = new GuzzleMockHandler([$response]);
        $handlerStack = GuzzleHandlerStack::create($mockHandler);

        $handlerStack->push($this->middleware->forGuzzle());
        $handlerStack->push(GuzzleMiddleware::history($history));

        $client = new GuzzleClient(['handler' => $handlerStack]);

        $options = ['timeout' => 90, 'answer' => 42];

        $ret = $client->request($method, '/foo', $options);

        $this->assertSame($response, $ret);

        $this->assertCount(1, $history);
        $this->assertInstanceOf(GuzzleRequest::class, $history[0]['request']);
        $this->assertEquals('', $history[0]['request']->getHeaderLine('Digest'));
        $this->assertSame($response, $history[0]['response']);
    }


    /**
     * @dataProvider digestMethodProvider
     */
    public function testAsHttplugMiddleware(string $method)
    {
        $digestRequest = $this->createMock(RequestInterface::class);

        $request = $this->createMockRequest($method, 'test');
        $request->expects($this->once())->method('withHeader')
            ->with('Digest', 'MD5=CY9rzUYh03PK3k6DJie09g==')
            ->willReturn($digestRequest);

        $response = $this->createMock(ResponseInterface::class);

        $this->service->expects($this->once())->method('create')
            ->with('test')
            ->willReturn('MD5=CY9rzUYh03PK3k6DJie09g==');

        $mockClient = new HttpMockClient();
        $mockClient->setDefaultResponse($response);

        $client = new HttpPluginClient($mockClient, [$this->middleware->forHttplug()]);

        $ret = $client->sendRequest($request);

        $this->assertSame($response, $ret);
    }

    /**
     * @dataProvider noDigestMethodProvider
     */
    public function testAsHttplugMiddlewareWithoutDigest(string $method)
    {
        $request = $this->createMockRequest($method);
        $request->expects($this->never())->method('withHeader');

        $response = $this->createMock(ResponseInterface::class);

        $this->service->expects($this->never())->method('create');

        $mockClient = new HttpMockClient();
        $mockClient->setDefaultResponse($response);

        $client = new HttpPluginClient($mockClient, [$this->middleware->forHttplug()]);

        $ret = $client->sendRequest($request);

        $this->assertSame($response, $ret);
    }
}
