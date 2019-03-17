<?php declare(strict_types=1);

namespace Jasny\HttpDigest;

use Improved as i;
use const Improved\FUNCTION_ARGUMENT_PLACEHOLDER as __;

use Improved\IteratorPipeline\Pipeline;
use Jasny\HttpDigest\Negotiation\DigestNegotiator;
use Jasny\HttpDigest\Negotiation\WantDigest;

/**
 * Create and verify HTTP Digests.
 */
class HttpDigest
{
    protected const ALGOS = [
        'MD5' => 'md5',
        'SHA' => 'sha1',
        'SHA-256' => 'sha256',
        'SHA-512' => 'sha512',
    ];

    /**
     * @var DigestNegotiator
     */
    protected $negotiator;

    /**
     * Supported algorithms with priority (RFC 7231).
     * @var string[]
     */
    protected $priorities;


    /**
     * Class construction.
     *
     * @param string|string[]  $priorities  RFC 7231 strings
     * @param DigestNegotiator $negotiator
     */
    public function __construct($priorities, DigestNegotiator $negotiator = null)
    {
        $this->priorities = $this->getSupportedPriorities($priorities);
        $this->negotiator = $negotiator ?? new DigestNegotiator();
    }

    /**
     * Set the priorities.
     *
     * @param string|string[] $priorities  RFC 7231 strings
     * @return static
     */
    public function withPriorities($priorities)
    {
        $supportedPriorities = $this->getSupportedPriorities($priorities);
        ;

        if ($this->priorities === $supportedPriorities) {
            return $this;
        }

        $clone = clone $this;
        $clone->priorities = $supportedPriorities;

        return $clone;
    }

    /**
     * Get the priorities.
     *
     * @return string[]
     */
    public function getPriorities(): array
    {
        return $this->priorities;
    }

    /**
     * Get the priorities as `Want-Digest` header string.
     *
     * @return string
     */
    public function getWantDigest(): string
    {
        return join(', ', $this->priorities);
    }

    /**
     * Get the priorities for supported algorithms.
     *
     * @param string|string[] $priorities  RFC 7231 strings (content negotiation).
     * @return array
     */
    protected function getSupportedPriorities($priorities): array
    {
        i\type_check($priorities, ['string', 'array']);

        $supportedPriorities = Pipeline::with(is_string($priorities) ? explode(',', $priorities): $priorities)
            ->typeCheck('string')
            ->map(i\function_partial('trim', __))
            ->map(i\function_partial('explode', ';', __, 2))
            ->column(1, 0)
            ->mapKeys(function ($_, string $algo) {
                return strtoupper($algo);
            })
            ->filter(function ($_, string $algo) {
                return array_key_exists($algo, self::ALGOS);
            })
            ->sortKeys(SORT_STRING)
            ->map(function (?string $q, string $algo) {
                return $algo . ($q !== null && $q !== '' ? ';' . $q : '');
            })
            ->values()
            ->toArray();

        if (count($supportedPriorities) === 0) {
            throw new \InvalidArgumentException('None of the algorithms specified in the priorities are supported');
        }

        return $supportedPriorities;
    }


    /**
     * Create a digest from the body.
     *
     * @param string $body
     * @return string
     */
    public function create(string $body): string
    {
        /** @var WantDigest $best */
        $best = $this->negotiator->getBest('*', $this->priorities);
        $algo = strtoupper($best->getValue());

        $hash = hash(self::ALGOS[$algo], $body, true);

        return sprintf('%s=%s', $algo, base64_encode($hash));
    }

    /**
     * Validate a digest is correct for the body.
     *
     * @param string $body
     * @param string $digest
     * @throws HttpDigestException
     */
    public function verify(string $body, string $digest): void
    {
        [$algo, $hash64] = explode('=', $digest, 2) + [1 => ''];

        if ($this->negotiator->getBest($algo, $this->priorities) === null) {
            throw new HttpDigestException('Unsupported digest hashing algorithm: '. $algo);
        }

        $hash = base64_decode($hash64, true);

        if ($hash === false) {
            throw new HttpDigestException('Corrupt digest hash');
        }

        $expectedHash = hash(self::ALGOS[$algo], $body, true);

        if ($hash !== $expectedHash) {
            throw new HttpDigestException('Incorrect digest hash');
        }
    }
}
