<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminReportRequest;
use App\Models\User;
use App\Services\Admin\AdminReport;
use App\Services\Admin\UsageReconciliation;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboard extends Controller
{
    /**
     * Read-only operator report. Access is enforced by AdminMiddleware on the route group.
     */
    public function __invoke(AdminReportRequest $request, AdminReport $report, UsageReconciliation $reconciliation): Response
    {
        $start = $request->start();
        $end = $request->end();
        $userId = $request->userId();

        $daily = $report->daily($start, $end, $userId);
        $filteredUser = $userId === null ? null : User::query()->select(['id', 'name', 'email'])->find($userId);

        return Inertia::render('admin/AdminDashboard', [
            'filters' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'user' => $filteredUser?->only(['id', 'name', 'email']),
            ],
            'summary' => $report->summary($daily),
            'daily' => $daily,
            'users' => $report->users($start, $end),
            'attention' => $report->attention(),
            // Calls Pixelforge, so it loads after the page has rendered. Always account-wide.
            'reconciliation' => Inertia::defer(fn (): array => $reconciliation->compare(
                $userId === null ? $daily : $report->daily($start, $end),
                $start,
                $end,
            )),
        ]);
    }
}
