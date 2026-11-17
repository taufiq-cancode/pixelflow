<?php

namespace App\Services\Pricing;

use App\Enums\TokenType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pixelforge's current price list and token tables, built from
 * GET /v1/pricing. Nothing here is hardcoded: if Pixelforge changes a rate,
 * a size or a token count, the next fetch picks it up.
 */
final readonly class PricingCatalog
{
    /**
     * @param  array<string, int>  $rateNanosPerToken  keyed by TokenType value
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => exact output tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public array $rateNanosPerToken,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<mixed>  $response  the decoded GET /v1/pricing body
     */
    public static function fromApiResponse(array $response): self
    {
        $rates = self::arrayAt($response, 'rates');
        $tokens = self::arrayAt($response, 'tokens');

        $rateNanosPerToken = [];
        foreach (TokenType::cases() as $type) {
            $rate = $rates[$type->value] ?? null;
            if (! is_string($rate)) {
                throw new InvalidArgumentException("Pricing is missing the {$type->value} rate.");
            }
            $rateNanosPerToken[$type->value] = Money::ratePerMillionToNanosPerToken($rate);
        }

        $outputTokens = [];
        foreach (self::arrayAt($tokens, 'output') as $quality => $sizes) {
            if (! is_array($sizes)) {
                throw new InvalidArgumentException("Pricing output tokens for {$quality} are malformed.");
            }
            foreach ($sizes as $size => $count) {
                $outputTokens[(string) $quality][(string) $size] = self::nonNegativeInt($count, "output.{$quality}.{$size}");
            }
        }

        $reasoningTokens = [];
        foreach (self::arrayAt($tokens, 'reasoning') as $quality => $range) {
            if (! is_array($range)) {
                throw new InvalidArgumentException("Pricing reasoning tokens for {$quality} are malformed.");
            }
            $reasoningTokens[(string) $quality] = [
                'min' => self::nonNegativeInt($range['min'] ?? null, "reasoning.{$quality}.min"),
                'max' => self::nonNegativeInt($range['max'] ?? null, "reasoning.{$quality}.max"),
            ];
        }

        $charactersPerToken = self::nonNegativeInt($tokens['characters_per_text_token'] ?? null, 'characters_per_text_token');
        if ($charactersPerToken === 0) {
            throw new InvalidArgumentException('Pricing characters_per_text_token must be positive.');
        }

        // The one float in the money path: Pixelforge sends the variance as
        // a JSON number (0.15). It only widens the *estimate*, and is turned
        // into integer basis points right here (0.15 → 1500).
        $variance = $tokens['text_token_variance'] ?? null;
        if (! is_int($variance) && ! is_float($variance)) {
            throw new InvalidArgumentException('Pricing text_token_variance is missing.');
        }
        $varianceBps = (int) round($variance * 10_000);
        if ($varianceBps < 0 || $varianceBps >= 10_000) {
            throw new InvalidArgumentException('Pricing text_token_variance is out of range.');
        }

        return new self(
            rateNanosPerToken: $rateNanosPerToken,
            charactersPerTextToken: $charactersPerToken,
            textTokenVarianceBps: $varianceBps,
            tokensPerReferenceImage: self::nonNegativeInt($tokens['per_reference_image'] ?? null, 'per_reference_image'),
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * @return list<string>
     */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * Sizes offered for every quality.
     *
     * @return list<string>
     */
    public function sizes(): array
    {
        $perQuality = array_map(array_keys(...), array_values($this->outputTokens));

        return $perQuality === [] ? [] : array_values(array_intersect(...$perQuality));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value)) {
            throw new InvalidArgumentException("Pricing response is missing \"{$key}\".");
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Pricing {$field} must be a non-negative integer.");
        }

        return $value;
    }
}
