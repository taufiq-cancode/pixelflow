<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\AdminUsageRequest;
use App\Services\UsageReport;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /admin/usage: credit spend, provider cost and profit per day and per
 * user, reconciled against Pixelforge's /v1/usage.
 */
final class UsageReportController
{
    public function __invoke(AdminUsageRequest $request, UsageReport $usageReport): Response
    {
        [$start, $end] = $request->range();

        return Inertia::render('admin/Usage', [
            'report' => UsageReport::present($usageReport->build($start, $end)),
        ]);
    }
}
