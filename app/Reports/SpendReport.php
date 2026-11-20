<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Credit spend, provider cost and profit per UTC day and per user.
 *
 * Days are the UTC day a generation *completed*, because that's how Pixelforge bills in
 * GET /v1/usage, so our per-day provider costs line up exactly with theirs.
 */
class SpendReport
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /** @return array<string, mixed> */
    public function build(Carbon $start, Carbon $end): array
    {
        $rows = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->groupBy(DB::raw('DATE(generations.completed_at)'), 'users.id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('DATE(generations.completed_at)'))
            ->orderBy('users.name')
            ->get([
                DB::raw('DATE(generations.completed_at) as day'),
                'users.id as user_id', 'users.name', 'users.email',
                DB::raw('COUNT(*) as generations'),
                DB::raw('SUM(generations.charge_nano) as charge_nano'),
                DB::raw('SUM(generations.cost_nano) as cost_nano'),
            ]);

        [$providerByDay, $providerError] = $this->providerUsage($start, $end);

        $days = [];
        foreach ($rows as $row) {
            $day = Carbon::parse($row->day)->toDateString();
            $days[$day] ??= ['date' => $day, 'users' => [], 'generations' => 0, 'charge' => 0, 'cost' => 0];
            $days[$day]['users'][] = $this->line((int) $row->generations, (int) $row->charge_nano, (int) $row->cost_nano) + [
                'user_id' => $row->user_id, 'name' => $row->name, 'email' => $row->email,
            ];
            $days[$day]['generations'] += (int) $row->generations;
            $days[$day]['charge'] += (int) $row->charge_nano;
            $days[$day]['cost'] += (int) $row->cost_nano;
        }

        // Include days that only Pixelforge has usage for, so mismatches can't hide.
        foreach ($providerByDay as $day => $usage) {
            if ($usage['generations'] > 0) {
                $days[$day] ??= ['date' => $day, 'users' => [], 'generations' => 0, 'charge' => 0, 'cost' => 0];
            }
        }
        krsort($days);

        $days = array_values(array_map(function (array $d) use ($providerByDay) {
            $provider = $providerByDay[$d['date']] ?? null;

            return $this->line($d['generations'], $d['charge'], $d['cost']) + [
                'date' => $d['date'],
                'users' => $d['users'],
                'pixelforge' => $provider ? [
                    'generations' => $provider['generations'],
                    'cost' => Money::nanoToDecimal($provider['cost_nano']),
                    'matches' => $provider['cost_nano'] === $d['cost'] && $provider['generations'] === $d['generations'],
                ] : null,
            ];
        }, $days));

        $totals = array_reduce($days, fn ($t, $d) => [
            'generations' => $t['generations'] + $d['generations'],
            'charge' => $t['charge'] + Money::decimalToNano($d['charge']),
            'cost' => $t['cost'] + Money::decimalToNano($d['cost']),
        ], ['generations' => 0, 'charge' => 0, 'cost' => 0]);

        return [
            'days' => $days,
            'totals' => $this->line($totals['generations'], $totals['charge'], $totals['cost']),
            'pixelforgeTotal' => $providerByDay === [] ? null : Money::nanoToDecimal(array_sum(array_column($providerByDay, 'cost_nano'))),
            'pixelforgeError' => $providerError,
        ];
    }

    /** @return array{generations: int, charge: string, cost: string, profit: string} */
    private function line(int $generations, int $chargeNano, int $costNano): array
    {
        return [
            'generations' => $generations,
            'charge' => Money::nanoToDecimal($chargeNano),
            'cost' => Money::nanoToDecimal($costNano),
            'profit' => Money::nanoToDecimal($chargeNano - $costNano),
        ];
    }

    /** @return array{0: array<string, array{generations: int, cost_nano: int}>, 1: ?string} */
    private function providerUsage(Carbon $start, Carbon $end): array
    {
        try {
            $usage = $this->client->usage($start->toDateString(), $end->toDateString());
        } catch (PixelforgeException $e) {
            return [[], $e->getMessage()];
        }

        $byDay = [];
        foreach ($usage['data'] as $day) {
            $byDay[$day['date']] = [
                'generations' => (int) $day['generations'],
                'cost_nano' => Money::decimalToNano($day['cost_usd']),
            ];
        }

        return [$byDay, null];
    }
}
