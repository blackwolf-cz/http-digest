<?php declare(strict_types=1);

namespace Jasny\HttpDigest\Negotiation;

use Negotiation\BaseAccept;
use Negotiation\AcceptHeader;

/**
 * Representation of `Want-Digest` header for content negotiation.
 */
final class WantDigest extends BaseAccept implements AcceptHeader
{
    /**
     * @return string
     */
    public function getType()
    {
        return strtoupper(parent::getType());
    }
}
