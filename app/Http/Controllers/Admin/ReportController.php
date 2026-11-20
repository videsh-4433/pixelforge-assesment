<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\SpendReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, SpendReport $report): Response
    {
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = Carbon::parse($data['end'] ?? now('UTC')->toDateString(), 'UTC');
        $start = Carbon::parse($data['start'] ?? $end->copy()->subDays(13)->toDateString(), 'UTC');

        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91); // Pixelforge's /v1/usage allows at most 92 days
        }

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            ...$report->build($start, $end),
        ]);
    }
}
