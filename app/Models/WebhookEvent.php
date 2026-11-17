<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A received Pixelforge webhook event. The unique event_id is what makes
 * duplicate deliveries harmless. Rows are written by HandlePixelforgeWebhook
 * with insertOrIgnore, so the model has no fillable attributes.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property int|null $delivery_attempt
 * @property array<string, mixed> $payload
 * @property string|null $outcome
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
