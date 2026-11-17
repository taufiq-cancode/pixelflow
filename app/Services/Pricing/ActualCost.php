<?php

namespace App\Services\Pricing;

/**
 * The real cost of a succeeded generation, from its reported usage and the
 * rates it was billed at. Amounts are integer nanodollars.
 */
final readonly class ActualCost
{
    /**
     * @param  array<string, int>  $tokens  keyed by TokenType value
     */
    public function __construct(
        public array $tokens,
        public int $providerCostNano,
        public int $userCostNano,
    ) {}
}
