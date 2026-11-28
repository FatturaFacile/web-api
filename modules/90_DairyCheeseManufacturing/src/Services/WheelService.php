<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;

/**
 * Aging cellar operations on the single wheels: filtering, position changes, weighing, consortium grading and fire-branding,
 * and the sale that decrements the wheel count and records the weighed weight against the book weight.
 */
final class WheelService
{
    public function __construct(private readonly WheelMetrics $metrics, private readonly VatBatchService $batches) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CheeseAgingWheel>
     */
    public function query(array $filters): Builder
    {
        $query = CheeseAgingWheel::query()->with(['batch', 'rack'])->orderBy('serial');
        $days = (float) config('dairy_cheese.days_per_month', 30.4375);

        foreach (['status', 'grade', 'vat_batch_id', 'rack_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        if (! empty($filters['cheese_type'])) {
            $query->whereHas('batch', static fn (Builder $b) => $b->where('cheese_type', (string) $filters['cheese_type']));
        }

        if (isset($filters['age_min']) && $filters['age_min'] !== '') {
            $limit = CarbonImmutable::today()->subDays((int) round((float) $filters['age_min'] * $days))->toDateString();
            $query->whereHas('batch', static fn (Builder $b) => $b->whereDate('production_date', '<=', $limit));
        }

        if (isset($filters['age_max']) && $filters['age_max'] !== '') {
            $limit = CarbonImmutable::today()->subDays((int) round((float) $filters['age_max'] * $days))->toDateString();
            $query->whereHas('batch', static fn (Builder $b) => $b->whereDate('production_date', '>=', $limit));
        }

        if (! empty($filters['search'])) {
            $term = '%'.mb_strtolower((string) $filters['search']).'%';
            $query->where(static fn (Builder $q) => $q->whereRaw('LOWER(serial) LIKE ?', [$term])->orWhereHas('batch', static fn (Builder $b) => $b->whereRaw('LOWER(code) LIKE ?', [$term])));
        }

        return $query;
    }

    /**
     * Totals of the dual inventory over every wheel that matches the filters.
     *
     * @param  Builder<CheeseAgingWheel>  $query
     * @return array{wheels: int, initial_kg: string, current_kg: string, theoretical_kg: string, loss_pct: string}
     */
    public function totals(Builder $query): array
    {
        $wheels = 0;
        $initial = $current = $theoretical = 0.0;

        foreach ((clone $query)->reorder()->lazyById(200) as $wheel) {
            $m = $this->metrics->of($wheel);
            $wheels++;
            $initial += $m['initial_kg'];
            $current += $m['current_kg'];
            $theoretical += $m['theoretical_kg'];
        }

        return [
            'wheels' => $wheels,
            'initial_kg' => number_format($initial, 3, '.', ''),
            'current_kg' => number_format($current, 3, '.', ''),
            'theoretical_kg' => number_format($theoretical, 3, '.', ''),
            'loss_pct' => number_format($initial > 0 ? ($initial - $current) / $initial * 100 : 0, 3, '.', ''),
        ];
    }

    public function move(CheeseAgingWheel $wheel, string $rackId, ?int $position): CheeseAgingWheel
    {
        $this->assertInCellar($wheel);

        return DB::transaction(function () use ($wheel, $rackId, $position): CheeseAgingWheel {
            $rack = AgingRack::query()->lockForUpdate()->findOrFail($rackId);

            if ($wheel->rack_id !== $rack->id) {
                $this->batches->assertCapacity($rack, 1);
            }

            $wheel->forceFill(['rack_id' => $rack->id, 'position' => $position, 'status' => $wheel->status === 'brine' ? 'aging' : $wheel->status])->save();

            return $this->reload($wheel);
        });
    }

    public function weigh(CheeseAgingWheel $wheel, float $weightKg): CheeseAgingWheel
    {
        $this->assertInCellar($wheel);

        return DB::transaction(function () use ($wheel, $weightKg): CheeseAgingWheel {
            $wheel->forceFill(['measured_weight_kg' => round($weightKg, 3), 'last_weighed_at' => now()])->save();
            $this->batches->refreshYield($wheel->batch);

            return $this->reload($wheel);
        });
    }

    public function grade(CheeseAgingWheel $wheel, string $grade, bool $branded, ?string $notes, ?string $userId = null): CheeseAgingWheel
    {
        $this->assertInCellar($wheel);

        if ($grade === 'ungraded') {
            throw new DairyCheeseException('Choose a grade.', 'grade_required');
        }

        if ($branded && ! in_array($grade, ['first_category', 'second_category'], true)) {
            throw new DairyCheeseException('Only a wheel that passed the expert grading can be fire-branded.', 'branding_requires_grade');
        }

        return DB::transaction(function () use ($wheel, $grade, $branded, $notes, $userId): CheeseAgingWheel {
            $wheel->forceFill([
                'grade' => $grade,
                'branded' => $branded,
                'branded_at' => $branded ? ($wheel->branded_at ?? now()) : null,
                'grading_notes' => $notes,
            ])->save();

            AgingCareLog::query()->create([
                'care_type' => 'expert_grading', 'status' => 'done', 'scope' => 'wheel', 'target_id' => $wheel->id, 'scheduled_for' => now()->toDateString(),
                'executed_at' => now(), 'wheels_affected' => 1, 'performed_by' => $userId, 'notes' => $notes,
            ]);

            return $this->reload($wheel);
        });
    }

    /**
     * @param  list<string>  $wheelIds
     * @param  array<string, float|int|string>  $weights  weighed weight of each wheel at the sale
     * @return array{sold: list<CheeseAgingWheel>, total_kg: string, total_variance_kg: string}
     */
    public function sell(array $wheelIds, ?string $customerId, string $customerName, array $weights, ?string $invoiceRef): array
    {
        return DB::transaction(function () use ($wheelIds, $customerId, $customerName, $weights, $invoiceRef): array {
            $sold = [];
            $total = 0.0;
            $variance = 0.0;
            $batchIds = [];

            foreach (array_unique($wheelIds) as $id) {
                $wheel = CheeseAgingWheel::query()->with('batch')->lockForUpdate()->findOrFail($id);
                $this->assertInCellar($wheel);

                if (! isset($weights[$id])) {
                    throw new DairyCheeseException('The sale weight of every wheel is required.', 'sale_weight_required');
                }

                $book = $this->metrics->of($wheel)['current_kg'];
                $kg = round((float) $weights[$id], 3);

                $wheel->forceFill([
                    'status' => 'sold', 'sold_at' => now(), 'sold_weight_kg' => $kg, 'sale_variance_kg' => round($kg - $book, 3),
                    'customer_id' => $customerId, 'customer_name' => $customerName, 'invoice_ref' => $invoiceRef, 'rack_id' => null, 'position' => null,
                ])->save();

                $total += $kg;
                $variance += $kg - $book;
                $batchIds[$wheel->vat_batch_id] = $wheel->batch;
                $sold[] = $this->reload($wheel);
            }

            foreach ($batchIds as $batch) {
                $this->batches->refreshYield($batch);
            }

            return ['sold' => $sold, 'total_kg' => number_format($total, 3, '.', ''), 'total_variance_kg' => number_format($variance, 3, '.', '')];
        });
    }

    public function reload(CheeseAgingWheel $wheel): CheeseAgingWheel
    {
        return $wheel->refresh()->load(['batch', 'rack']);
    }

    private function assertInCellar(CheeseAgingWheel $wheel): void
    {
        if (! in_array($wheel->status, ['brine', 'aging'], true)) {
            throw new DairyCheeseException('The wheel is no longer in the cellar.', 'wheel_not_in_cellar');
        }
    }
}
