<?php

namespace App\Http\Requests;

use App\Services\UsageReport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;

final class AdminUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-usage-report') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:start',
                function (string $attribute, mixed $value, Closure $fail): void {
                    [$start, $end] = $this->range();
                    if (Date::parse($start)->diffInDays(Date::parse($end)) + 1 > UsageReport::MAX_DAYS) {
                        $fail('The report can cover at most '.UsageReport::MAX_DAYS.' days.');
                    }
                },
            ],
        ];
    }

    /**
     * The UTC date range, defaulting to the last 30 days.
     *
     * @return array{0: string, 1: string}
     */
    public function range(): array
    {
        $end = $this->string('end')->toString() ?: Date::now()->utc()->toDateString();
        $start = $this->string('start')->toString() ?: Date::parse($end)->subDays(29)->toDateString();

        return [$start, $end];
    }
}
