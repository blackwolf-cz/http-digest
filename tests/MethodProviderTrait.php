<?php declare(strict_types=1);

namespace Jasny\HttpDigest\Tests;

trait MethodProviderTrait
{
    public function alwaysDigestMethodProvider()
    {
        return [
            ['POST'],
            ['PUT'],
            ['PATCH'],
        ];
    }

    public function neverDigestMethodProvider()
    {
        return [
            ['GET'],
            ['HEAD'],
        ];
    }

    public function maybeDigestMethodProvider()
    {
        return [
            ['DELETE'],
            ['CUSTOM'],
        ];
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

    public function anyMethodProvider()
    {
        return [
            ['GET'],
            ['HEAD'],
            ['POST'],
            ['PUT'],
            ['PATCH'],
            ['DELETE'],
            ['CUSTOM'],
        ];
    }
}