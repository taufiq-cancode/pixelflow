<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UsesPricingCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for the live estimate shown while the user fills in the form. The
 * prompt may still be empty, and only the number of reference images
 * matters for the price.
 */
final class EstimateGenerationRequest extends FormRequest
{
    use UsesPricingCatalog;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $catalog = $this->pricingCatalog();

        return [
            'prompt' => ['nullable', 'string', 'max:'.config()->integer('pixelforge.max_prompt_length')],
            'size' => ['required', 'string', Rule::in($catalog->sizes())],
            'quality' => ['required', 'string', Rule::in($catalog->qualities())],
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:'.config()->integer('pixelforge.max_reference_images')],
        ];
    }
}
