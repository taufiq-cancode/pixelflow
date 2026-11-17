<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UsesPricingCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGenerationRequest extends FormRequest
{
    use UsesPricingCatalog;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Mirrors Pixelforge's own limits, so a request we accept is one
     * Pixelforge accepts too (it rejects unknown or invalid parameters).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $catalog = $this->pricingCatalog();

        return [
            'prompt' => ['required', 'string', 'max:'.config()->integer('pixelforge.max_prompt_length')],
            'size' => ['required', 'string', Rule::in($catalog->sizes())],
            'quality' => ['required', 'string', Rule::in($catalog->qualities())],
            'reference_images' => ['array', 'max:'.config()->integer('pixelforge.max_reference_images')],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.max' => 'You can add at most :max reference images.',
        ];
    }

    /**
     * The form always sends every reference image field, so drop the
     * empty ones before validating.
     */
    protected function prepareForValidation(): void
    {
        $urls = $this->input('reference_images', []);

        $this->merge([
            'reference_images' => is_array($urls)
                ? array_values(array_filter($urls, fn ($url) => ! is_string($url) || trim($url) !== ''))
                : $urls,
        ]);
    }

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    public function generationInput(): array
    {
        /** @var list<string> $referenceImages */
        $referenceImages = array_values($this->validated('reference_images', []));

        return [
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => $referenceImages,
        ];
    }
}
