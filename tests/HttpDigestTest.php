<?php

namespace Jasny\HttpDigest\Tests;

use InvalidArgumentException;
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Negotiation\BaseAccept;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Jasny\HttpDigest\HttpDigest
 */
class HttpDigestTest extends TestCase
{
    /**
     * @var DigestNegotiator&MockObject
     */
    protected $negotiator;

    public function setUp(): void
    {
        $this->negotiator = $this->createMock(DigestNegotiator::class);
    }

    public function prioritiesProvider()
    {
        return  [
            [['MD5;q=0.3', 'sha;q=1'], ['MD5;q=0.3', 'sha;q=0.5', 'SHA-256']],
            ['MD5;q=0.3, sha;q=1', 'MD5;q=0.3, sha;q=0.5, SHA-256'],
        ];
    }

    /**
     * @dataProvider prioritiesProvider
     */
    public function testPriorities($base, $alt)
    {
        $service = new HttpDigest($base, $this->negotiator);

        $this->assertEquals(['MD5;q=0.3', 'SHA;q=1'], $service->getPriorities());
        $this->assertEquals('MD5;q=0.3, SHA;q=1', $service->getWantDigest());

        $this->assertSame($service, $service->withPriorities(['SHA;q=1', 'MD5;q=0.3']));
        $this->assertSame($service, $service->withPriorities(['SHA;q=1', 'Foo;q=1', 'MD5;q=0.3']));

        $newService = $service->withPriorities($alt);
        $this->assertNotSame($service, $newService);

        $this->assertEquals(['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'], $newService->getPriorities());
        $this->assertEquals('MD5;q=0.3, SHA;q=0.5, SHA-256', $newService->getWantDigest());
    }

    public function testPrioritiesNoSupportedInConstructor()
    {
        $this->expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('None of the algorithms specified in the priorities are supported');
        new HttpDigest(['Foo;q=1', 'Bar;q=0.3'], $this->negotiator);
    }

    public function testPrioritiesNoSupportedInClone()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("None of the algorithms specified in the priorities are supported");
        $service = new HttpDigest('MD5', $this->negotiator);
        $service->withPriorities('Foo');
    }


    public function hashProvider()
    {
        return [
            ['MD5=CY9rzUYh03PK3k6DJie09g==', 'MD5', ['MD5']],
            ['SHA=qUqP5cyxm6YcTAhz05Hph5gvu9M=', 'SHA', ['MD5;q=0.3', 'SHA;q=1']],
            [
                'SHA-256=n4bQgYhMfWWaL+qgxVrQFaO/TxsrC4Is0V1sFbDwCgg=',
                'SHA-256',
                ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256']
            ],
        ];
    }

    /**
     * @dataProvider hashProvider
     */
    public function testCreate(string $expected, string $algo, array $prios)
    {
        $this->expectNegotiate($algo, $prios, '*');

        $service = new HttpDigest($prios, $this->negotiator);
        $digest = $service->create('test');

        $this->assertEquals($expected, $digest);
    }

    /**
     * @dataProvider hashProvider
     */
    public function testVerify(string $digest, string $algo)
    {
        $prios = ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'];
        $this->expectNegotiate($algo, $prios);

        $service = new HttpDigest($prios, $this->negotiator);
        $service->verify('test', $digest);

        $this->assertTrue(true); // No exceptions
    }

    /**
     * @dataProvider hashProvider
     */
    public function testVerifyWithIncorrectDigest(string $digest, string $algo)
    {
        $prios = ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'];
        $this->expectNegotiate($algo, $prios);

        $service = new HttpDigest($prios, $this->negotiator);

        $this->expectException(\Jasny\HttpDigest\HttpDigestException::class);
        $this->expectExceptionMessage("Incorrect digest hash");

        $service->verify('some content', $digest);
    }

    public function testVerifyWithInvalidDigest()
    {
        $hash = hash('sha256', 'test', true); // Not base64 encoded

        $prios = ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'];
        $this->expectNegotiate('SHA-256', $prios);

        $service = new HttpDigest($prios, $this->negotiator);

        $this->expectExceptionMessage("Corrupt digest hash");
        $this->expectException(\Jasny\HttpDigest\HttpDigestException::class);

        $service->verify('test', 'SHA-256=' . $hash);
    }

    public function testVerifyWithUnsupportedAlgorithm()
    {
        $this->negotiator->expects($this->once())->method('getBest')
            ->with('MD5', ['SHA;q=0.5', 'SHA-256'])
            ->willReturn(null);

        $service = new HttpDigest(['SHA-256', 'SHA;q=0.5'], $this->negotiator);

        $this->expectExceptionMessage("Unsupported digest hashing algorithm: MD5");
        $this->expectException(\Jasny\HttpDigest\HttpDigestException::class);

        $service->verify('test', 'MD5=CY9rzUYh03PK3k6DJie09g==');
    }


    /**
     * Expect the negotiator to be used.
     *
     * @param string      $algo
     * @param string[]    $prios
     * @param string|null $accept
     */
    protected function expectNegotiate(string $algo, array $prios, ?string $accept = null): void
    {
        $best = $this->createPartialMock(BaseAccept::class, ['getValue']);
        $best->expects($this->any())->method('getValue')->willReturn($algo);

        $this->negotiator->expects($this->once())->method('getBest')
            ->with($accept ?? $algo, $prios)
            ->willReturn($best);
    }
}
