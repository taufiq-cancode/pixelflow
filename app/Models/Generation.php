<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_cost_nano
 * @property int $estimated_max_cost_nano
 * @property array<string, string>|null $provider_rates
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read GenerationCost|null $cost
 */
#[Fillable([
    'idempotency_key',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_min_cost_nano',
    'estimated_max_cost_nano',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'provider_rates' => 'array',
            'estimated_min_cost_nano' => 'integer',
            'estimated_max_cost_nano' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<GenerationCost, $this>
     */
    public function cost(): HasOne
    {
        return $this->hasOne(GenerationCost::class);
    }

    /**
     * The body for POST /v1/generations.
     *
     * It is built only from stored columns, so every retry sends exactly the
     * same parameters with the same Idempotency-Key (Pixelforge answers 409
     * if a key is reused with different parameters). The metadata lets a
     * webhook that arrives before the POST response find this row.
     *
     * @return array<string, mixed>
     */
    public function toPixelforgePayload(): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => ['pixelflow_generation_id' => (string) $this->id],
        ];

        if ($this->reference_images !== []) {
            $payload['reference_images'] = $this->reference_images;
        }

        return $payload;
    }
}
