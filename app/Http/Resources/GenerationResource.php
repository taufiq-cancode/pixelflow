<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the user sees it. Money is sent as exact decimal strings
 * (never floats) and only the user's own price is exposed: provider cost
 * stays internal.
 *
 * @mixin Generation
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cost = $this->cost;
        $imageExpired = $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // Accepted by us but not by Pixelforge yet (e.g. all three of
            // Pixelforge's concurrent slots are busy).
            'waiting_for_slot' => $this->status === GenerationStatus::Queued && $this->pixelforge_id === null,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_min_usd' => Money::format($this->estimated_min_cost_nano),
            'estimated_max_usd' => Money::format($this->estimated_max_cost_nano),
            'actual_cost_usd' => match ($this->status) {
                GenerationStatus::Succeeded => $cost === null ? null : Money::format($cost->user_cost_nano),
                // Failed generations are never charged.
                GenerationStatus::Failed => Money::format(0),
                default => null,
            },
            'usage' => $cost === null || ! $cost->user_charged ? null : [
                'input_text' => $cost->input_text_tokens,
                'input_image' => $cost->input_image_tokens,
                'output' => $cost->output_tokens,
                'reasoning' => $cost->reasoning_tokens,
            ],
            'image_url' => $this->status === GenerationStatus::Succeeded && ! $imageExpired ? $this->image_url : null,
            'image_expired' => $this->status === GenerationStatus::Succeeded && $imageExpired,
            'error_message' => $this->status === GenerationStatus::Failed ? $this->error_message : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
