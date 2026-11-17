<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Queued,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_cost_nano' => 0,
            'estimated_max_cost_nano' => 0,
        ];
    }

    /**
     * A generation Pixelforge has accepted.
     */
    public function submitted(string $status = 'queued'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::from($status),
        ])->afterMaking(function (Generation $generation) {
            $generation->pixelforge_id = 'gen_'.Str::upper(Str::random(26));
            $generation->last_synced_at = now();
        });
    }
}
