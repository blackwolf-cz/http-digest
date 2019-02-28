<?php

namespace Jasny\HttpDigest\Tests;

use InvalidArgumentException;
use Jasny\HttpDigest\HttpDigest;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Negotiation\AcceptHeader;
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

    public function setUp()
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
        $service = new HttpDigest($this->negotiator, $base);

        $this->assertEquals(['MD5;q=0.3', 'SHA;q=1'], $service->getPriorities());
        $this->assertEquals('MD5;q=0.3, SHA;q=1', $service->getWantDigest());

        $this->assertSame($service, $service->withPriorities(['SHA;q=1', 'MD5;q=0.3']));
        $this->assertSame($service, $service->withPriorities(['SHA;q=1', 'Foo;q=1', 'MD5;q=0.3']));

        $newService = $service->withPriorities($alt);
        $this->assertNotSame($service, $newService);

        $this->assertEquals(['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'], $newService->getPriorities());
        $this->assertEquals('MD5;q=0.3, SHA;q=0.5, SHA-256', $newService->getWantDigest());
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage None of the algorithms specified in the priorities are supported
     */
    public function testPrioritiesNoSupportedInConstructor()
    {
        new HttpDigest($this->negotiator, ['Foo;q=1', 'Bar;q=0.3']);
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage None of the algorithms specified in the priorities are supported
     */
    public function testPrioritiesNoSupportedInClone()
    {
        $service = new HttpDigest($this->negotiator, ['MD5']);
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

        $service = new HttpDigest($this->negotiator, $prios);
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

        $service = new HttpDigest($this->negotiator, $prios);
        $service->verify('test', $digest);

        $this->assertTrue(true); // No exceptions
    }

    /**
     * @dataProvider hashProvider
     * @expectedException \Jasny\HttpDigest\HttpDigestException
     * @expectedExceptionMessage Incorrect digest hash
     */
    public function testVerifyWithIncorrectDigest(string $digest, string $algo)
    {
        $prios = ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'];
        $this->expectNegotiate($algo, $prios);

        $service = new HttpDigest($this->negotiator, $prios);
        $service->verify('some content', $digest);
    }

    /**
     * @expectedException \Jasny\HttpDigest\HttpDigestException
     * @expectedExceptionMessage Corrupt digest hash
     */
    public function testVerifyWithInvalidDigest()
    {
        $hash = hash('sha256', 'test', true); // Not base64 encoded

        $prios = ['MD5;q=0.3', 'SHA;q=0.5', 'SHA-256'];
        $this->expectNegotiate('SHA-256', $prios);

        $service = new HttpDigest($this->negotiator, $prios);
        $service->verify('test', 'SHA-256=' . $hash);
    }

    /**
     * @expectedException \Jasny\HttpDigest\HttpDigestException
     * @expectedExceptionMessage Unsupported digest hashing algorithm: MD5
     */
    public function testVerifyWithUnsupportedAlgorithm()
    {
        $this->negotiator->expects($this->once())->method('getBest')
            ->with('MD5', ['SHA;q=0.5', 'SHA-256'])
            ->willReturn(null);

        $service = new HttpDigest($this->negotiator, ['SHA-256', 'SHA;q=0.5']);
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
        $best = $this->createPartialMock(AcceptHeader::class, ['getValue']);
        $best->expects($this->any())->method('getValue')->willReturn($algo);

        $this->negotiator->expects($this->once())->method('getBest')
            ->with($accept ?? $algo, $prios)
            ->willReturn($best);
    }
}
