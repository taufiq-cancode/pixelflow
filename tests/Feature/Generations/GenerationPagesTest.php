<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_generate_page_offers_sizes_and_qualities_from_the_price_list()
    {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::pricing())]);

        $this->actingAs(User::factory()->create())
            ->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('generations/Create')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.qualities', ['low', 'medium', 'high'])
                ->where('limits.maxPromptLength', 4000)
                ->where('limits.maxReferenceImages', 4));
    }

    public function test_the_generate_page_still_loads_when_pricing_is_unavailable()
    {
        Sleep::fake();
        Http::fake(['pixelforge.test/v1/pricing' => Http::response([], 503)]);

        $this->actingAs(User::factory()->create())
            ->get(route('generations.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('generations/Create')->where('options', null));
    }

    public function test_history_shows_only_the_users_own_generations_with_costs()
    {
        $user = User::factory()->create();
        $succeeded = Generation::factory()->for($user)->submitted('succeeded')->create([
            'estimated_min_cost_nano' => 58_187_500,
            'estimated_max_cost_nano' => 86_337_500,
        ]);
        GenerationCost::create([
            'generation_id' => $succeeded->id,
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1500,
            'provider_cost_nano' => Pixelforge::PROVIDER_COST_NANO,
            'user_cost_nano' => Pixelforge::USER_COST_NANO,
            'markup_bps' => 2500,
            'user_charged' => true,
            'billed_on' => '2026-11-04',
        ]);
        Generation::factory()->for($user)->create([
            'status' => GenerationStatus::Failed,
            'error_message' => 'The model could not produce an image for this prompt. You were not charged.',
        ]);
        Generation::factory()->create(); // someone else's

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('hasInFlight', false)
                // Newest first: the failed one.
                ->where('generations.data.0.status', 'failed')
                ->where('generations.data.0.actual_cost_usd', '0.000000000')
                ->where('generations.data.0.error_message', 'The model could not produce an image for this prompt. You were not charged.')
                ->where('generations.data.1.status', 'succeeded')
                ->where('generations.data.1.estimated_min_usd', '0.058187500')
                ->where('generations.data.1.estimated_max_usd', '0.086337500')
                ->where('generations.data.1.actual_cost_usd', '0.078512500')
                ->where('generations.data.1.usage.reasoning', 1500)
                ->missing('generations.data.1.provider_cost_nano'));
    }

    public function test_history_reports_unfinished_generations_so_the_page_keeps_polling()
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(); // queued, not yet accepted

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('hasInFlight', true)
                ->where('generations.data.0.status', 'queued')
                ->where('generations.data.0.waiting_for_slot', true)
                ->where('generations.data.0.actual_cost_usd', null));
    }

    public function test_an_expired_image_link_is_not_sent_to_the_browser()
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->submitted('succeeded')->create();
        $generation->forceFill([
            'image_url' => 'https://pixelforge.test/images/gen.png',
            'image_url_expires_at' => now()->subHour(),
        ])->save();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('generations.data.0.image_url', null)
                ->where('generations.data.0.image_expired', true));
    }
}
