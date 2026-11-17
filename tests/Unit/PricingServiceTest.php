<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingCatalog;
use App\Services\Pricing\PricingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    private const DOCS_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function catalog(): PricingCatalog
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/pixelforge/pricing.json');

        return PricingCatalog::fromApiResponse(json_decode((string) $json, true));
    }

    private function pricing(int $markupBps = 2500): PricingService
    {
        return new PricingService($markupBps);
    }

    public function test_estimate_matches_the_docs_worked_example()
    {
        // Docs: high quality 1536x1024, 400-character prompt, 2 reference
        // images. The maximum is $0.373875 from Pixelforge.
        $estimate = $this->pricing()->estimate($this->catalog(), str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->textTokensMax);
        $this->assertSame(1_530, $estimate->imageTokens);
        $this->assertSame(6_450, $estimate->outputTokens);
        $this->assertSame(8_000, $estimate->reasoningTokensMax);
        $this->assertSame(373_875_000, $estimate->providerMaxNano);
        $this->assertSame(467_343_750, $estimate->userMaxNano); // × 1.25

        // Minimum: 85 text tokens and 1,000 reasoning tokens.
        $this->assertSame(85, $estimate->textTokensMin);
        $this->assertSame(1_000, $estimate->reasoningTokensMin);
        // 85×5,000 + 1,530×10,000 + 6,450×40,000 + 1,000×12,500
        $this->assertSame(286_225_000, $estimate->providerMinNano);
        $this->assertSame(357_781_250, $estimate->userMinNano);
    }

    public function test_estimate_counts_unicode_characters_not_bytes()
    {
        // 8 characters but 16 bytes: ceil(8 / 4) = 2 tokens, not 4.
        $estimate = $this->pricing()->estimate($this->catalog(), 'éééééééé', '1024x1024', 'low', 0);

        $this->assertSame(1, $estimate->textTokensMin); // floor(2 × 0.85)
        $this->assertSame(3, $estimate->textTokensMax); // ceil(2 × 1.15)
    }

    public function test_text_token_range_is_rounded_outward()
    {
        // 9 characters → ceil(9 / 4) = 3 tokens; 3 × 0.85 = 2.55, 3 × 1.15 = 3.45.
        $estimate = $this->pricing()->estimate($this->catalog(), str_repeat('x', 9), '1024x1024', 'low', 0);

        $this->assertSame(2, $estimate->textTokensMin);
        $this->assertSame(4, $estimate->textTokensMax);
    }

    public function test_estimate_of_an_empty_prompt_has_no_text_tokens()
    {
        $estimate = $this->pricing()->estimate($this->catalog(), '', '1024x1024', 'low', 0);

        $this->assertSame(0, $estimate->textTokensMin);
        $this->assertSame(0, $estimate->textTokensMax);
        // Low quality square: 280 output × 40,000; reasoning 0..500 × 12,500.
        $this->assertSame(11_200_000, $estimate->providerMinNano);
        $this->assertSame(17_450_000, $estimate->providerMaxNano);
    }

    public function test_estimate_rejects_an_unknown_size_or_quality()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pricing()->estimate($this->catalog(), 'hello', '512x512', 'medium', 0);
    }

    public function test_actual_cost_matches_the_docs_usage_example()
    {
        $cost = $this->pricing()->actualCost([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], self::DOCS_RATES);

        // The docs report cost_usd "0.169080000" for exactly this usage.
        $this->assertSame(169_080_000, $cost->providerCostNano);
        $this->assertSame(211_350_000, $cost->userCostNano);
    }

    public function test_actual_cost_matches_the_docs_succeeded_generation()
    {
        $cost = $this->pricing()->actualCost([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ], self::DOCS_RATES);

        // 12×5,000 + 765×10,000 + 6,450×40,000 + 3,184×12,500
        $this->assertSame(305_510_000, $cost->providerCostNano);
        $this->assertSame(381_887_500, $cost->userCostNano);
        $this->assertSame(3184, $cost->tokens['reasoning_tokens']);
    }

    public function test_actual_cost_uses_the_generations_own_rates()
    {
        $cost = $this->pricing()->actualCost([
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 1000,
            'reasoning_tokens' => 0,
        ], [...self::DOCS_RATES, 'output_tokens' => '50.00']);

        $this->assertSame(50_000_000, $cost->providerCostNano);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidUsage(): array
    {
        return [
            'missing token type' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1]],
            'negative count' => [['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0]],
            'string count' => [['input_text_tokens' => '12', 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    #[DataProvider('invalidUsage')]
    public function test_actual_cost_rejects_malformed_usage(array $usage)
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pricing()->actualCost($usage, self::DOCS_RATES);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function markupRounding(): array
    {
        return [
            'zero stays zero' => [0, 0],
            '1 × 1.25 = 1.25 rounds up' => [1, 2],
            '2 × 1.25 = 2.5 rounds up' => [2, 3],
            '3 × 1.25 = 3.75 rounds up' => [3, 4],
            '4 × 1.25 = 5 is exact' => [4, 5],
            'micro-dollar multiples are exact' => [1_000, 1_250],
            'docs worked example' => [373_875_000, 467_343_750],
        ];
    }

    #[DataProvider('markupRounding')]
    public function test_user_cost_applies_the_markup_and_rounds_up_to_a_whole_nanodollar(int $provider, int $user)
    {
        $this->assertSame($user, $this->pricing()->userCost($provider));
    }

    public function test_markup_is_configurable()
    {
        $this->assertSame(1_000, $this->pricing(0)->userCost(1_000));
        $this->assertSame(2_000, $this->pricing(10_000)->userCost(1_000));
    }

    public function test_markup_cannot_be_negative()
    {
        $this->expectException(InvalidArgumentException::class);

        new PricingService(-1);
    }

    public function test_markup_is_applied_once_to_the_total_not_per_token_type()
    {
        // 1 text token (5,000) + 1 image token (10,000) + 1 output token
        // (40,000) + 1 reasoning token (12,500) = 67,500 → × 1.25 = 84,375.
        $cost = $this->pricing()->actualCost([
            'input_text_tokens' => 1,
            'input_image_tokens' => 1,
            'output_tokens' => 1,
            'reasoning_tokens' => 1,
        ], self::DOCS_RATES);

        $this->assertSame(67_500, $cost->providerCostNano);
        $this->assertSame(84_375, $cost->userCostNano);
    }
}
