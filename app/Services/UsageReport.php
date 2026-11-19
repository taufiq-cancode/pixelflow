<?php

namespace App\Services;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * The operators' report: per UTC day (and per user), what users were
 * charged, what Pixelforge charged us and the profit, reconciled day by day
 * against Pixelforge's own GET /v1/usage (DECISIONS.md §20).
 *
 * Every figure is a sum of stored per-generation integers (nanodollars);
 * nothing is recomputed or re-rounded, so per-generation rows always add up
 * exactly to these totals.
 */
final class UsageReport
{
    /** Pixelforge's limit for one /v1/usage request. */
    public const MAX_DAYS = 92;

    private const TOKEN_FIELDS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array{
     *     start: string,
     *     end: string,
     *     days: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     pixelforge_error: string|null,
     *     reconciled: bool|null,
     * }
     */
    public function build(string $start, string $end): array
    {
        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
        $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC');

        if ($startDate === null || $endDate === null || $endDate->lt($startDate)) {
            throw new InvalidArgumentException('The report needs a valid date range.');
        }

        if ($startDate->diffInDays($endDate) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('The report can cover at most '.self::MAX_DAYS.' days.');
        }

        $ours = $this->ourDailyTotals($start, $end);
        [$theirs, $pixelforgeError] = $this->pixelforgeDailyUsage($start, $end);

        $days = [];
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            $key = $date->toDateString();
            $day = $ours[$key] ?? $this->emptyTotals();
            $provider = $theirs[$key] ?? null;

            $days[] = [
                'date' => $key,
                ...$day,
                'pixelforge' => $provider,
                'matches' => $provider === null ? null : $this->matches($day, $provider),
            ];
        }

        $days = array_reverse($days); // newest first
        $matchFlags = array_column($days, 'matches');

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'users' => $this->perUserPerDay($start, $end),
            'totals' => $this->sumDays($days),
            'pixelforge_error' => $pixelforgeError,
            'reconciled' => $pixelforgeError !== null ? null : ! in_array(false, $matchFlags, true),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function ourDailyTotals(string $start, string $end): array
    {
        $rows = DB::table('generation_costs')
            ->whereBetween('billed_on', [$start, $end])
            ->groupBy('billed_on')
            ->selectRaw('billed_on, count(*) as generations,
                sum(input_text_tokens) as input_text_tokens, sum(input_image_tokens) as input_image_tokens,
                sum(output_tokens) as output_tokens, sum(reasoning_tokens) as reasoning_tokens,
                sum(user_cost_nano) as charged_nano, sum(provider_cost_nano) as provider_nano')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->billed_on] = $this->totalsFromRow($row);
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perUserPerDay(string $start, string $end): array
    {
        $rows = DB::table('generation_costs as costs')
            ->join('generations', 'generations.id', '=', 'costs.generation_id')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->whereBetween('costs.billed_on', [$start, $end])
            ->groupBy('costs.billed_on', 'users.id', 'users.name', 'users.email')
            ->selectRaw('costs.billed_on, users.id as user_id, users.name, users.email, count(*) as generations,
                sum(costs.input_text_tokens) as input_text_tokens, sum(costs.input_image_tokens) as input_image_tokens,
                sum(costs.output_tokens) as output_tokens, sum(costs.reasoning_tokens) as reasoning_tokens,
                sum(costs.user_cost_nano) as charged_nano, sum(costs.provider_cost_nano) as provider_nano')
            ->orderByDesc('costs.billed_on')
            ->orderByDesc('charged_nano')
            ->get();

        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'date' => (string) $row->billed_on,
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                ...$this->totalsFromRow($row),
            ];
        }

        return $users;
    }

    /**
     * Pixelforge's per-day usage for this API key, or the reason it couldn't
     * be loaded.
     *
     * @return array{0: array<string, array<string, int>>, 1: string|null}
     */
    private function pixelforgeDailyUsage(string $start, string $end): array
    {
        try {
            $response = $this->client->usage($start, $end);
        } catch (PixelforgeException $exception) {
            return [[], 'Pixelforge usage could not be loaded: '.$exception->getMessage()];
        }

        $days = [];
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_string($day['cost_usd'] ?? null)) {
                continue;
            }

            $days[$day['date']] = [
                'generations' => (int) ($day['generations'] ?? 0),
                ...array_combine(self::TOKEN_FIELDS, array_map(fn ($field) => (int) ($day[$field] ?? 0), self::TOKEN_FIELDS)),
                'cost_nano' => Money::usdToNanos($day['cost_usd']),
            ];
        }

        return [$days, null];
    }

    /**
     * A day reconciles when the generation count, every token count and the
     * exact cost all equal what Pixelforge reports.
     *
     * @param  array<string, int>  $ours
     * @param  array<string, int>  $theirs
     */
    private function matches(array $ours, array $theirs): bool
    {
        if ($ours['generations'] !== $theirs['generations'] || $ours['provider_nano'] !== $theirs['cost_nano']) {
            return false;
        }

        foreach (self::TOKEN_FIELDS as $field) {
            if ($ours[$field] !== $theirs[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function totalsFromRow(stdClass $row): array
    {
        $charged = (int) $row->charged_nano;
        $provider = (int) $row->provider_nano;

        return [
            'generations' => (int) $row->generations,
            'input_text_tokens' => (int) $row->input_text_tokens,
            'input_image_tokens' => (int) $row->input_image_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'reasoning_tokens' => (int) $row->reasoning_tokens,
            'charged_nano' => $charged,
            'provider_nano' => $provider,
            'profit_nano' => $charged - $provider,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        return [
            'generations' => 0,
            ...array_fill_keys(self::TOKEN_FIELDS, 0),
            'charged_nano' => 0,
            'provider_nano' => 0,
            'profit_nano' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function sumDays(array $days): array
    {
        $totals = $this->emptyTotals();
        $pixelforgeCost = 0;
        $pixelforgeGenerations = 0;
        $hasPixelforge = false;

        foreach ($days as $day) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (int) $day[$field];
            }

            if (is_array($day['pixelforge'])) {
                $hasPixelforge = true;
                $pixelforgeCost += (int) $day['pixelforge']['cost_nano'];
                $pixelforgeGenerations += (int) $day['pixelforge']['generations'];
            }
        }

        return [
            ...$totals,
            'pixelforge_cost_nano' => $hasPixelforge ? $pixelforgeCost : null,
            'pixelforge_generations' => $hasPixelforge ? $pixelforgeGenerations : null,
        ];
    }

    /**
     * The report with money as exact decimal strings, for the UI.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function present(array $report): array
    {
        $money = fn (array $row): array => [
            ...$row,
            'charged_usd' => Money::format((int) $row['charged_nano']),
            'provider_usd' => Money::format((int) $row['provider_nano']),
            'profit_usd' => Money::format((int) $row['profit_nano']),
        ];

        /** @var list<array<string, mixed>> $days */
        $days = $report['days'];
        /** @var list<array<string, mixed>> $users */
        $users = $report['users'];
        /** @var array<string, mixed> $totals */
        $totals = $report['totals'];

        return [
            ...$report,
            'days' => array_map(fn (array $day) => [
                ...$money($day),
                'pixelforge' => is_array($day['pixelforge'])
                    ? [...$day['pixelforge'], 'cost_usd' => Money::format((int) $day['pixelforge']['cost_nano'])]
                    : null,
            ], $days),
            'users' => array_map($money, $users),
            'totals' => [
                ...$money($totals),
                'pixelforge_cost_usd' => $totals['pixelforge_cost_nano'] === null ? null : Money::format((int) $totals['pixelforge_cost_nano']),
            ],
        ];
    }
}
