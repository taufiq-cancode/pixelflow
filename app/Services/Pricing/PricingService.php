<?php

namespace App\Services\Pricing;

use App\Enums\TokenType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure pricing maths: no HTTP, no database, no floats. Every amount is an
 * integer number of nanodollars. See DECISIONS.md §4, §5 and §9.
 */
final readonly class PricingService
{
    private const BASIS_POINTS = 10_000;

    public function __construct(public int $markupBps)
    {
        if ($markupBps < 0) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }
    }

    /**
     * The price range shown before submitting.
     *
     * Text tokens: Pixelforge estimates ceil(characters / chars_per_token)
     * and the real count is within ±variance of that, so we round the range
     * outward (floor the min, ceil the max) to be certain it contains the
     * real count. Image and output tokens are exact. Reasoning tokens fall in
     * the published range for the quality.
     */
    public function estimate(
        PricingCatalog $catalog,
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount,
    ): CostEstimate {
        $outputTokens = $catalog->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("No output token count for {$quality} {$size}.");
        $reasoning = $catalog->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("No reasoning token range for {$quality}.");

        // Unicode characters, not bytes (the docs are explicit about this).
        $textEstimate = self::ceilDiv(mb_strlen($prompt), $catalog->charactersPerTextToken);
        $textMin = intdiv($textEstimate * (self::BASIS_POINTS - $catalog->textTokenVarianceBps), self::BASIS_POINTS);
        $textMax = self::ceilDiv($textEstimate * (self::BASIS_POINTS + $catalog->textTokenVarianceBps), self::BASIS_POINTS);
        $imageTokens = $referenceImageCount * $catalog->tokensPerReferenceImage;

        $providerMin = $this->providerCost([
            TokenType::InputText->value => $textMin,
            TokenType::InputImage->value => $imageTokens,
            TokenType::Output->value => $outputTokens,
            TokenType::Reasoning->value => $reasoning['min'],
        ], $catalog->rateNanosPerToken);

        $providerMax = $this->providerCost([
            TokenType::InputText->value => $textMax,
            TokenType::InputImage->value => $imageTokens,
            TokenType::Output->value => $outputTokens,
            TokenType::Reasoning->value => $reasoning['max'],
        ], $catalog->rateNanosPerToken);

        return new CostEstimate(
            textTokensMin: $textMin,
            textTokensMax: $textMax,
            imageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokensMin: $reasoning['min'],
            reasoningTokensMax: $reasoning['max'],
            providerMinNano: $providerMin,
            providerMaxNano: $providerMax,
            userMinNano: $this->userCost($providerMin),
            userMaxNano: $this->userCost($providerMax),
        );
    }

    /**
     * The real cost of a succeeded generation, from the `usage` and `rates`
     * on its own Pixelforge object. Those rates were fixed when it was
     * created, so a later price change can't affect what it costs.
     *
     * @param  array<mixed>  $usage
     * @param  array<mixed>  $rates
     */
    public function actualCost(array $usage, array $rates): ActualCost
    {
        $tokens = [];
        $rateNanosPerToken = [];

        foreach (TokenType::cases() as $type) {
            $count = $usage[$type->value] ?? null;
            if (! is_int($count) || $count < 0) {
                throw new InvalidArgumentException("Usage has no valid {$type->value} count.");
            }

            $rate = $rates[$type->value] ?? null;
            if (! is_string($rate)) {
                throw new InvalidArgumentException("Rates have no {$type->value} rate.");
            }

            $tokens[$type->value] = $count;
            $rateNanosPerToken[$type->value] = Money::ratePerMillionToNanosPerToken($rate);
        }

        $providerCost = $this->providerCost($tokens, $rateNanosPerToken);

        return new ActualCost($tokens, $providerCost, $this->userCost($providerCost));
    }

    /**
     * What Pixelforge charges: the sum of tokens × rate for each token type.
     * Exact, never rounded, exactly as Pixelforge bills it.
     *
     * @param  array<string, int>  $tokens  keyed by TokenType value
     * @param  array<string, int>  $rateNanosPerToken  keyed by TokenType value
     */
    public function providerCost(array $tokens, array $rateNanosPerToken): int
    {
        $total = 0;

        foreach (TokenType::cases() as $type) {
            $total += $tokens[$type->value] * $rateNanosPerToken[$type->value];
        }

        return $total;
    }

    /**
     * What the user pays: provider cost plus markup, applied once to the
     * generation's total and rounded UP to a whole nanodollar, so our margin
     * is never below the markup. With today's rates the result is always
     * exact and the rounding never actually kicks in.
     */
    public function userCost(int $providerCostNano): int
    {
        return self::ceilDiv($providerCostNano * (self::BASIS_POINTS + $this->markupBps), self::BASIS_POINTS);
    }

    /**
     * Integer ceiling division for non-negative numbers.
     */
    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
