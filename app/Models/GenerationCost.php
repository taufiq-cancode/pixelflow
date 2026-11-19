<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single billing record of a succeeded generation. Amounts are integer
 * nanodollars (1e-9 USD).
 *
 * @property int $id
 * @property int $generation_id
 * @property int $input_text_tokens
 * @property int $input_image_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property int $provider_cost_nano
 * @property int $user_cost_nano
 * @property int $markup_bps
 * @property bool $user_charged
 * @property string $billed_on UTC date (Y-m-d) the generation completed: Pixelforge's billing day
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Generation $generation
 */
#[Fillable([
    'generation_id',
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
    'provider_cost_nano',
    'user_cost_nano',
    'markup_bps',
    'user_charged',
    'billed_on',
])]
class GenerationCost extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nano' => 'integer',
            'user_cost_nano' => 'integer',
            'markup_bps' => 'integer',
            'user_charged' => 'boolean',
            // Kept as a plain "Y-m-d" string, not a date cast: a date cast
            // stores "Y-m-d 00:00:00" on SQLite, which breaks the per-day
            // grouping and range filters the usage report relies on.
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
