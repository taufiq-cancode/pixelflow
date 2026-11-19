<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class CreateGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Pixelforge's price list, faked per test (Http::fake keeps the first
     * matching stub, so it can't be overridden later in a test).
     */
    private function fakePricing(int $status = 200): void
    {
        Http::fake(['pixelforge.test/v1/pricing' => $status === 200
            ? Http::response(Pixelforge::pricing())
            : Http::response(['message' => 'unavailable'], $status)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_replace([
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ], $overrides);
    }

    public function test_guests_cannot_generate()
    {
        $this->post(route('generations.store'), $this->validInput())->assertRedirect(route('login'));
        $this->postJson(route('generations.estimate'), [])->assertUnauthorized();

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_a_generation_is_stored_with_its_estimate_and_queued_for_submission()
    {
        $this->fakePricing();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), $this->validInput())
            ->assertRedirect(route('generations.index'))
            ->assertSessionHasNoErrors();

        $generation = Generation::sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->pixelforge_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generation->idempotency_key);
        // The docs' worked example, with the 25% markup.
        $this->assertSame(357_781_250, $generation->estimated_min_cost_nano);
        $this->assertSame(467_343_750, $generation->estimated_max_cost_nano);

        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generationId === $generation->id);
    }

    public function test_empty_reference_image_fields_are_ignored()
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validInput(['reference_images' => ['', 'https://example.com/a.png', '  ']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['https://example.com/a.png'], Generation::sole()->reference_images);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing prompt' => [['prompt' => ''], 'prompt'],
            'prompt over 4,000 characters' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
            'unknown size' => [['size' => '512x512'], 'size'],
            'unknown quality' => [['quality' => 'ultra'], 'quality'],
            'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
            'not an http url' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
            'not a url' => [['reference_images' => ['hello']], 'reference_images.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(array $overrides, string $field)
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validInput($overrides))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_prompt_of_exactly_4000_unicode_characters_is_allowed()
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validInput(['prompt' => str_repeat('é', 4000)]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('generations', 1);
    }

    public function test_nothing_is_created_when_pricing_is_unavailable()
    {
        Sleep::fake();
        $this->fakePricing(503);

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validInput())
            ->assertSessionHasErrors('pricing');

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_the_live_estimate_returns_the_user_price_range_and_breakdown()
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), [
                'prompt' => str_repeat('a', 400),
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_image_count' => 2,
            ])
            ->assertOk()
            ->assertExactJson([
                'min_usd' => '0.357781250',
                'max_usd' => '0.467343750',
                'tokens' => [
                    'input_text' => ['min' => 85, 'max' => 115],
                    'input_image' => 1530,
                    'output' => 6450,
                    'reasoning' => ['min' => 1000, 'max' => 8000],
                ],
            ]);
    }

    public function test_the_live_estimate_works_before_a_prompt_is_typed()
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low', 'reference_image_count' => 0])
            ->assertOk()
            // 280 output tokens (+ 0..500 reasoning) at the docs' rates, × 1.25.
            ->assertJson(['min_usd' => '0.014000000', 'max_usd' => '0.021812500']);
    }

    public function test_the_live_estimate_validates_its_input()
    {
        $this->fakePricing();

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), ['size' => 'huge', 'quality' => 'low', 'reference_image_count' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size', 'reference_image_count']);
    }
}
