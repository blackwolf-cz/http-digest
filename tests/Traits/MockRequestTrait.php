<?php declare(strict_types=1);

namespace Jasny\HttpDigest\Tests\Traits;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

trait MockRequestTrait
{
    /**
     * Create a mock digest request.
     *
     * @param string $method
     * @param string $contents
     * @return RequestInterface&MockObject
     */
    protected function createMockRequest(string $method, string $contents = ''): ServerRequestInterface
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects($this->any())->method('getContents')->willReturn($contents);
        $body->expects($this->any())->method('getSize')->willReturn(strlen($contents));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->any())->method('getMethod')->willReturn($method);
        $request->expects($this->any())->method('getBody')->willReturn($body);

        return $request;
    }
}
