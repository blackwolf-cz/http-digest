<?php

namespace Jasny\HttpDigest\Tests\Negotiation;

use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Jasny\HttpDigest\Negotiation\WantDigest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Jasny\HttpDigest\Negotiation\DigestNegotiator
 * @covers \Jasny\HttpDigest\Negotiation\WantDigest
 */
class DigestNegotiatorTest extends TestCase
{
    public function resultProvider()
    {
        return [
            ['SHA-256', 'SHA-256', ['SHA;q=0.5', 'SHA-256']],
            ['SHA', 'SHA', ['SHA;q=0.5', 'SHA-256']],
            ['SHA-256', 'SHA,SHA-256', ['SHA;q=0.5', 'SHA-256']],
            ['SHA', 'SHA,SHA-256;q=0.1', ['SHA;q=0.5', 'SHA-256']],
        ];
    }

    /**
     * @dataProvider resultProvider
     */
    public function test(string $expect, string $header, array $priorities)
    {
        $negotiator = new DigestNegotiator();

        /** @var WantDigest $best */
        $best = $negotiator->getBest($header, $priorities);

        $this->assertInstanceOf(WantDigest::class, $best);
        $this->assertEquals($expect, $best->getType());
    }


    public function noResultProvider()
    {
        return [
            ['MD5', ['SHA;q=0.5', 'SHA-256']],
            ['MD5=0.5;SHA-512=1', ['SHA;q=0.5', 'SHA-256']],
        ];
    }

    /**
     * @dataProvider noResultProvider
     */
    public function testNoResult(string $header, array $priorities)
    {
        $negotiator = new DigestNegotiator();
        $best = $negotiator->getBest($header, $priorities);

        $this->assertNull($best);
    }
}
