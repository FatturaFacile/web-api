<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;

/**
 * Dairy dashboard: intake of the day, quality trends of the last 30 days, vat batches, cellar occupancy (wheels and current kg
 * after weight loss), upcoming care tasks and the declaration deadlines.
 */
final class DashboardService
{
    public function __construct(private readonly WheelMetrics $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $date = null): array
    {
        $day = $date === null ? CarbonImmutable::today() : CarbonImmutable::parse($date)->startOfDay();
        $from = $day->subDays(29);

        $today = MilkDelivery::query()->whereDate('collection_date', $day->toDateString())->where('status', '!=', 'rejected');
        $tests = QualityTest::query()->whereBetween('sample_date', [$from->toDateString(), $day->toDateString()])->get();

        $wheels = CheeseAgingWheel::query()->with('batch')->whereIn('status', ['brine', 'aging'])->get();
        $kg = round($wheels->sum(fn (CheeseAgingWheel $w): float => $this->metrics->of($w)['current_kg']), 3);
        $capacity = (int) AgingRack::query()->sum('capacity_wheels');

        $overdue = AgingCareLog::query()->where('status', 'scheduled')->whereDate('scheduled_for', '<', $day->toDateString())->count();
        $upcoming = AgingCareLog::query()->where('status', 'scheduled')->whereDate('scheduled_for', '<=', $day->addDays(7)->toDateString())->orderBy('scheduled_for')->limit(10)->get();

        return [
            'kpis' => [
                'milk_today_liters' => number_format((float) (clone $today)->sum('liters'), 3, '.', ''),
                'milk_today_kg' => number_format((float) (clone $today)->sum('kg'), 3, '.', ''),
                'deliveries_today' => (clone $today)->count(),
                'avg_fat_pct' => $this->avg($tests, 'fat_pct', 3),
                'avg_protein_pct' => $this->avg($tests, 'protein_pct', 3),
                'avg_scc' => $this->avg($tests, 'scc_cells_ml', 0),
                'active_vat_batches' => CheeseVatBatch::query()->whereIn('status', ['open', 'in_production'])->count(),
                'cellar_wheels' => $wheels->count(),
                'cellar_current_kg' => number_format($kg, 3, '.', ''),
                'cellar_capacity_wheels' => $capacity,
                'occupancy_pct' => number_format($capacity > 0 ? $wheels->count() / $capacity * 100 : 0, 1, '.', ''),
                'positive_inhibitor_alerts' => QualityTest::query()->where('inhibitors', 'positive')->whereDate('sample_date', '>=', $day->subDays(7)->toDateString())->count(),
            ],
            'trends' => $this->trends($from, $day, $tests),
            'upcoming_care' => $upcoming,
            'overdue_care_count' => $overdue,
            'filing_deadlines' => $this->deadlines($day),
        ];
    }

    /**
     * @param  Collection<int, QualityTest>  $tests
     */
    private function avg($tests, string $field, int $scale): ?string
    {
        $values = $tests->pluck($field)->filter(static fn ($v): bool => $v !== null);

        return $values->isEmpty() ? null : number_format((float) $values->avg(), $scale, '.', '');
    }

    /**
     * @param  Collection<int, QualityTest>  $tests
     * @return list<array<string, string|null>>
     */
    private function trends(CarbonImmutable $from, CarbonImmutable $to, $tests): array
    {
        $intake = MilkDelivery::query()->whereBetween('collection_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'rejected')->get()
            ->groupBy(static fn (MilkDelivery $d): string => $d->collection_date->toDateString());
        $byDay = $tests->groupBy(static fn (QualityTest $t): string => $t->sample_date->toDateString());
        $rows = [];

        foreach ($intake->keys()->merge($byDay->keys())->unique()->sort() as $key) {
            $dayTests = $byDay->get($key, collect());
            $rows[] = [
                'date' => (string) $key,
                'liters' => number_format((float) ($intake->get($key)?->sum('liters') ?? 0), 3, '.', ''),
                'fat_pct' => $this->avg($dayTests, 'fat_pct', 3),
                'protein_pct' => $this->avg($dayTests, 'protein_pct', 3),
                'scc' => $this->avg($dayTests, 'scc_cells_ml', 0),
            ];
        }

        return $rows;
    }

    /**
     * The declarations of the previous month and the one before it that are still due.
     *
     * @return list<array<string, mixed>>
     */
    private function deadlines(CarbonImmutable $today): array
    {
        $rows = [];

        foreach (['dbmilch', 'sian'] as $authority) {
            foreach ([1, 2] as $back) {
                $period = $today->startOfMonth()->subMonths($back);
                $due = $period->addMonth()->startOfMonth()->addDays((int) config('dairy_cheese.filing_due_day.'.$authority, 10) - 1);
                $latest = RegulatorySubmission::query()->where('authority', $authority)->where('period', $period->format('Y-m'))->whereIn('status', ['generated', 'submitted'])->orderByDesc('generated_at')->first();
                $status = $latest === null ? 'pending' : $latest->status;

                if ($status === 'submitted') {
                    continue;
                }

                $rows[] = ['authority' => $authority, 'period' => $period->format('Y-m'), 'due_date' => $due->toDateString(), 'status' => $status, 'days_left' => (int) $today->diffInDays($due, false)];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['due_date'] <=> $b['due_date']);

        return $rows;
    }
}
