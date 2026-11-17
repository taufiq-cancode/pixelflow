<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon $billed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
            'billed_on' => 'date',
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
