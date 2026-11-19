<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\GenerationCost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The database itself refuses to double-charge, independently of the
 * application logic that normally prevents it.
 */
class MoneyConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function costRow(Generation $generation): array
    {
        return [
            'generation_id' => $generation->id,
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1500,
            'provider_cost_nano' => 62_810_000,
            'user_cost_nano' => 78_512_500,
            'markup_bps' => 2500,
            'user_charged' => true,
            'billed_on' => '2026-11-04',
        ];
    }

    public function test_a_generation_can_only_have_one_cost_record()
    {
        $generation = Generation::factory()->submitted('succeeded')->create();
        GenerationCost::create($this->costRow($generation));

        $this->expectException(UniqueConstraintViolationException::class);

        GenerationCost::create($this->costRow($generation));
    }

    public function test_two_generations_cannot_claim_the_same_pixelforge_generation()
    {
        $first = Generation::factory()->submitted()->create();
        $second = Generation::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $second->forceFill(['pixelforge_id' => $first->pixelforge_id])->save();
    }

    public function test_idempotency_keys_are_unique()
    {
        $first = Generation::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Generation::factory()->create(['idempotency_key' => $first->idempotency_key]);
    }
}
