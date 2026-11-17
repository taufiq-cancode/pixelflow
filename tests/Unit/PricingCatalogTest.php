<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PricingCatalogTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function response(): array
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/pixelforge/pricing.json');

        return json_decode((string) $json, true);
    }

    public function test_it_reads_rates_and_token_tables_from_the_pricing_response()
    {
        $catalog = PricingCatalog::fromApiResponse($this->response());

        $this->assertSame([
            'input_text_tokens' => 5_000,
            'input_image_tokens' => 10_000,
            'output_tokens' => 40_000,
            'reasoning_tokens' => 12_500,
        ], $catalog->rateNanosPerToken);
        $this->assertSame(4, $catalog->charactersPerTextToken);
        $this->assertSame(1_500, $catalog->textTokenVarianceBps);
        $this->assertSame(765, $catalog->tokensPerReferenceImage);
        $this->assertSame(6_450, $catalog->outputTokens['high']['1536x1024']);
        $this->assertSame(['min' => 200, 'max' => 2000], $catalog->reasoningTokens['medium']);
    }

    public function test_sizes_and_qualities_come_from_the_response()
    {
        $catalog = PricingCatalog::fromApiResponse($this->response());

        $this->assertSame(['low', 'medium', 'high'], $catalog->qualities());
        $this->assertSame(['1024x1024', '1536x1024', '1024x1536'], $catalog->sizes());
    }

    public function test_it_rejects_a_response_with_a_missing_rate()
    {
        $response = $this->response();
        unset($response['rates']['reasoning_tokens']);

        $this->expectException(InvalidArgumentException::class);

        PricingCatalog::fromApiResponse($response);
    }

    public function test_it_rejects_a_non_integer_token_count()
    {
        $response = $this->response();
        $response['tokens']['output']['low']['1024x1024'] = '280';

        $this->expectException(InvalidArgumentException::class);

        PricingCatalog::fromApiResponse($response);
    }
}
