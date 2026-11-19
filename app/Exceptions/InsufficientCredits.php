<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * The user can't afford the maximum estimate of a generation.
 */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNano,
        public readonly int $availableNano,
    ) {
        parent::__construct(sprintf(
            'This generation can cost up to $%s, but you have $%s available. Buy credits to continue.',
            Money::format($requiredNano),
            Money::format(max(0, $availableNano)),
        ));
    }
}
