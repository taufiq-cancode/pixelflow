<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * The upfront price range of a generation. "min" assumes the fewest text
 * and reasoning tokens Pixelforge can use, "max" the most. Image and output
 * tokens are exact. Every amount is integer nanodollars.
 */
final readonly class CostEstimate
{
    public function __construct(
        public int $textTokensMin,
        public int $textTokensMax,
        public int $imageTokens,
        public int $outputTokens,
        public int $reasoningTokensMin,
        public int $reasoningTokensMax,
        public int $providerMinNano,
        public int $providerMaxNano,
        public int $userMinNano,
        public int $userMaxNano,
    ) {}

    /**
     * What the user sees: their price range (markup included) and the token
     * breakdown behind it. Provider cost stays internal.
     *
     * @return array<string, mixed>
     */
    public function toUserArray(): array
    {
        return [
            'min_usd' => Money::format($this->userMinNano),
            'max_usd' => Money::format($this->userMaxNano),
            'tokens' => [
                'input_text' => ['min' => $this->textTokensMin, 'max' => $this->textTokensMax],
                'input_image' => $this->imageTokens,
                'output' => $this->outputTokens,
                'reasoning' => ['min' => $this->reasoningTokensMin, 'max' => $this->reasoningTokensMax],
            ],
        ];
    }
}
