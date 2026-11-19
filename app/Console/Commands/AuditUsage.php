<?php

namespace App\Console\Commands;

use App\Services\UsageReport;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Compares our recorded provider costs with Pixelforge's GET /v1/usage,
 * day by day. Exits non-zero if any day doesn't match exactly.
 */
final class AuditUsage extends Command
{
    protected $signature = 'pixelflow:audit-usage
        {--start= : First UTC date (YYYY-MM-DD), default 29 days before --end}
        {--end= : Last UTC date (YYYY-MM-DD), default today}';

    protected $description = 'Reconcile recorded provider costs with Pixelforge /v1/usage';

    public function handle(UsageReport $usageReport): int
    {
        $end = (string) ($this->option('end') ?: Date::now()->utc()->toDateString());
        $start = (string) ($this->option('start') ?: Date::parse($end)->subDays(29)->toDateString());

        try {
            $report = $usageReport->build($start, $end);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($report['pixelforge_error'] !== null) {
            $this->error($report['pixelforge_error']);

            return self::FAILURE;
        }

        $rows = [];
        foreach ($report['days'] as $day) {
            if ($day['generations'] === 0 && ($day['pixelforge']['generations'] ?? 0) === 0) {
                continue; // nothing on either side
            }

            $rows[] = [
                $day['date'],
                $day['generations'].' / '.($day['pixelforge']['generations'] ?? '?'),
                Money::format((int) $day['provider_nano']),
                isset($day['pixelforge']['cost_nano']) ? Money::format((int) $day['pixelforge']['cost_nano']) : '?',
                Money::format((int) $day['charged_nano']),
                Money::format((int) $day['profit_nano']),
                $day['matches'] ? 'yes' : 'NO',
            ];
        }

        $this->table(
            ['Date (UTC)', 'Generations ours / Pixelforge', 'Provider cost (ours)', 'Pixelforge cost_usd', 'Charged to users', 'Profit', 'Exact match'],
            $rows,
        );

        $totals = $report['totals'];
        $this->line(sprintf(
            'Total %s → %s: provider cost %s (Pixelforge %s), charged %s, profit %s.',
            $start,
            $end,
            Money::format((int) $totals['provider_nano']),
            Money::format((int) $totals['pixelforge_cost_nano']),
            Money::format((int) $totals['charged_nano']),
            Money::format((int) $totals['profit_nano']),
        ));

        if ($report['reconciled'] !== true) {
            $this->error('Some days do not match Pixelforge exactly.');

            return self::FAILURE;
        }

        $this->info('Every day matches Pixelforge exactly.');

        return self::SUCCESS;
    }
}
