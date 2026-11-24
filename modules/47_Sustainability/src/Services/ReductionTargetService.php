<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgReductionTarget;
use Modules\Sustainability\Support\Decimal;

/**
 * Absolute emission reduction targets. A draft target becomes active when its baseline (the emissions of the baseline
 * year for the scope) is frozen; an active target is then decided: achieved as soon as the latest inventory year shows
 * the committed reduction, missed once the target year is over without it.
 */
final readonly class ReductionTargetService
{
    public function __construct(private InventoryService $inventory, private SustainabilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): EsgReductionTarget
    {
        $this->assertYears((int) $data['baseline_year'], (int) $data['target_year']);

        return EsgReductionTarget::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'scope' => $data['scope'],
            'baseline_year' => $data['baseline_year'],
            'target_year' => $data['target_year'],
            'reduction_percent' => $data['reduction_percent'],
            'status' => EsgReductionTarget::Draft,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EsgReductionTarget $target, array $data): EsgReductionTarget
    {
        $structural = array_intersect_key($data, array_flip(['scope', 'baseline_year', 'target_year', 'reduction_percent']));

        if ($structural !== [] && $target->status !== EsgReductionTarget::Draft) {
            throw ValidationException::withMessages(['status' => [__('sustainability_errors.target.locked')]]);
        }

        $this->assertYears((int) ($data['baseline_year'] ?? $target->baseline_year), (int) ($data['target_year'] ?? $target->target_year));
        $target->update(array_intersect_key($data, array_flip(['name', 'scope', 'baseline_year', 'target_year', 'reduction_percent', 'notes'])));

        return $target->refresh();
    }

    public function activate(EsgReductionTarget $target): EsgReductionTarget
    {
        return DB::transaction(function () use ($target): EsgReductionTarget {
            $locked = $this->lock($target, [EsgReductionTarget::Draft]);
            $baseline = $this->inventory->emissionsOfYear((string) $locked->tenant_id, $locked->baseline_year, $locked->scope);

            if (! Decimal::isPositive($baseline)) {
                throw ValidationException::withMessages(['baseline_year' => [__('sustainability_errors.target.baseline_missing', ['scope' => $locked->scope, 'year' => $locked->baseline_year])]]);
            }

            $locked->update(['status' => EsgReductionTarget::Active, 'baseline_co2e_kg' => $baseline, 'activated_at' => now()]);
            $this->ledger->record('ESG_TARGET_ACTIVATED', $locked, ['scope' => $locked->scope, 'baseline_year' => $locked->baseline_year, 'target_year' => $locked->target_year, 'baseline_co2e_kg' => $baseline]);

            return $locked->refresh();
        });
    }

    /**
     * Decides an active target against the latest inventory year.
     */
    public function evaluate(EsgReductionTarget $target): EsgReductionTarget
    {
        return DB::transaction(function () use ($target): EsgReductionTarget {
            $locked = $this->lock($target, [EsgReductionTarget::Active]);
            $progress = $this->progress($locked);
            $reached = $progress !== null && $progress['reduction_achieved_percent'] !== null && Decimal::compare($progress['reduction_achieved_percent'], (string) $locked->reduction_percent) >= 0;

            if (! $reached && (int) now()->format('Y') <= $locked->target_year) {
                throw ValidationException::withMessages(['status' => [__('sustainability_errors.target.not_yet_decidable', ['year' => $locked->target_year])]]);
            }

            $status = $reached ? EsgReductionTarget::Achieved : EsgReductionTarget::Missed;
            $locked->update(['status' => $status, 'evaluated_at' => now()]);
            $this->ledger->record('ESG_TARGET_EVALUATED', $locked, ['status' => $status, 'current_year' => $progress['current_year'] ?? null]);

            return $locked->refresh();
        });
    }

    public function cancel(EsgReductionTarget $target): EsgReductionTarget
    {
        return DB::transaction(function () use ($target): EsgReductionTarget {
            $locked = $this->lock($target, [EsgReductionTarget::Draft, EsgReductionTarget::Active]);
            $locked->update(['status' => EsgReductionTarget::Cancelled]);
            $this->ledger->record('ESG_TARGET_CANCELLED', $locked);

            return $locked->refresh();
        });
    }

    /**
     * Progress against the frozen baseline, from the latest year after the baseline with recorded emissions (up to the
     * target year). The linear path is the reduction expected by that year if the target is met evenly.
     *
     * @return array<string, mixed>|null null while the baseline is not frozen
     */
    public function progress(EsgReductionTarget $target): ?array
    {
        if ($target->baseline_co2e_kg === null) {
            return null;
        }

        $baseline = (string) $target->baseline_co2e_kg;
        $percent = (string) $target->reduction_percent;
        $goal = Decimal::round(Decimal::mul($baseline, Decimal::div(Decimal::sub('100', $percent), '100')), 4);
        $lastYear = min($target->target_year, (int) now()->format('Y'));
        $currentYear = null;
        $current = null;

        for ($year = $lastYear; $year > $target->baseline_year; $year--) {
            $emissions = $this->inventory->emissionsOfYear((string) $target->tenant_id, $year, $target->scope);
            if (Decimal::isPositive($emissions)) {
                $currentYear = $year;
                $current = $emissions;
                break;
            }
        }

        $achieved = $current === null ? null : Decimal::round(Decimal::div(Decimal::mul(Decimal::sub($baseline, $current), '100'), $baseline), 2);
        $span = $target->target_year - $target->baseline_year;
        $expected = $currentYear === null ? null : Decimal::round(Decimal::mul($percent, Decimal::div((string) ($currentYear - $target->baseline_year), (string) $span)), 2);

        return [
            'baseline_co2e_kg' => Decimal::round($baseline, 4),
            'target_co2e_kg' => $goal,
            'current_year' => $currentYear,
            'current_co2e_kg' => $current,
            'reduction_achieved_percent' => $achieved,
            'expected_reduction_percent' => $expected,
            'progress_percent' => $achieved === null ? null : Decimal::round(Decimal::div(Decimal::mul($achieved, '100'), $percent), 2),
            'on_track' => $achieved === null || $expected === null ? null : Decimal::compare($achieved, $expected) >= 0,
        ];
    }

    private function assertYears(int $baselineYear, int $targetYear): void
    {
        if ($targetYear <= $baselineYear) {
            throw ValidationException::withMessages(['target_year' => [__('sustainability_errors.target.year_order')]]);
        }
    }

    /**
     * @param  list<string>  $from
     */
    private function lock(EsgReductionTarget $target, array $from): EsgReductionTarget
    {
        $locked = EsgReductionTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $from, true)) {
            throw ValidationException::withMessages(['status' => [__('sustainability_errors.target.status_expected', ['status' => $locked->status, 'expected' => implode(' / ', $from)])]]);
        }

        return $locked;
    }
}
