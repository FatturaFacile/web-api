<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Models\GdoScaleTier;
use Modules\GdoTradeTerms\Models\GdoSettlementLine;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GdoTradeTerms\Support\NotNull;
use Modules\GdoTradeTerms\Support\ScopeOverlap;

/**
 * Tiered year-end rebate scales of a contract. The brackets must rise, the window must sit inside the contract and two active
 * scales may not count the same sale; a scale that already settled a rebate keeps its brackets.
 */
final class ScaleService
{
    public function __construct(private readonly ContractService $contracts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(GdoContract $contract, ?GdoScale $scale, array $data, ?string $userId): GdoScale
    {
        $this->contracts->assertEditable($contract);

        $tiers = $this->validatedTiers($data['tiers'] ?? ($scale?->tierRows() ?? []));
        $start = isset($data['starts_on']) ? Carbon::parse((string) $data['starts_on']) : ($scale?->starts_on);
        $end = isset($data['ends_on']) ? Carbon::parse((string) $data['ends_on']) : ($scale?->ends_on);
        $this->assertWindow($contract, $start, $end);

        $active = (bool) ($data['is_active'] ?? $scale->is_active ?? true);

        if ($active && $tiers === []) {
            throw new GdoTradeTermsException('tiers_required');
        }

        if ($scale !== null && $this->hasSettled($scale) && $this->changesRebate($scale, $data)) {
            throw new GdoTradeTermsException('scale_settled', 409);
        }

        $attributes = NotNull::strip(array_intersect_key($data, array_flip([
            'code', 'name', 'tier_mode', 'basis', 'volume_mode', 'baseline_source', 'baseline_value', 'starts_on', 'ends_on',
            'category_ids', 'brand_ids', 'product_ids', 'delivery_point_ids', 'is_active', 'notes',
        ])), ['baseline_source', 'baseline_value', 'is_active']);

        return DB::transaction(function () use ($contract, $scale, $attributes, $tiers, $userId, $data): GdoScale {
            $model = $scale ?? new GdoScale(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'created_by' => $userId]);
            $model->fill([...$attributes, 'updated_by' => $userId])->save();
            $this->assertNoOverlap($contract, $model);

            if (array_key_exists('tiers', $data) || $scale === null) {
                $model->tiers()->delete();

                foreach ($tiers as $index => $tier) {
                    GdoScaleTier::create([
                        'tenant_id' => $contract->tenant_id,
                        'scale_id' => $model->id,
                        'position' => $index + 1,
                        'threshold' => $tier['threshold'],
                        'rate_percent' => $tier['rate_percent'],
                        'flat_amount' => $tier['flat_amount'],
                        'label' => $tier['label'],
                        'created_by' => $userId,
                    ]);
                }
            }

            return $model->refresh()->load('tiers');
        });
    }

    public function delete(GdoScale $scale, ?string $userId): void
    {
        if ($this->hasSettled($scale) || DB::table('gdt_accrual_lines')->where('source_id', $scale->id)->where('status', '!=', 'reversed')->whereNull('deleted_at')->exists()) {
            throw new GdoTradeTermsException('scale_has_accruals', 409);
        }

        DB::transaction(function () use ($scale, $userId): void {
            $scale->tiers()->delete();
            $scale->update(['deleted_by' => $userId]);
            $scale->delete();
        });
    }

    /**
     * @param  array<int|string, mixed>  $tiers
     * @return list<array{threshold: string, rate_percent: string, flat_amount: string|null, label: string|null}>
     */
    private function validatedTiers(array $tiers): array
    {
        $rows = [];
        $seen = [];

        foreach (array_values($tiers) as $tier) {
            $threshold = Money::round((string) ($tier['threshold'] ?? '0'));
            $rate = Money::round((string) ($tier['rate_percent'] ?? '0'));

            if (Money::isNegative($threshold) || Money::isNegative($rate) || Money::compare($rate, '100') > 0) {
                throw new GdoTradeTermsException('tier_invalid');
            }

            if (isset($tier['flat_amount']) && Money::isNegative((string) $tier['flat_amount'])) {
                throw new GdoTradeTermsException('tier_invalid');
            }

            if (in_array($threshold, $seen, true)) {
                throw new GdoTradeTermsException('tier_duplicate_threshold');
            }

            $seen[] = $threshold;
            $rows[] = [
                'threshold' => $threshold,
                'rate_percent' => $rate,
                'flat_amount' => isset($tier['flat_amount']) && $tier['flat_amount'] !== '' ? Money::round((string) $tier['flat_amount']) : null,
                'label' => isset($tier['label']) && $tier['label'] !== '' ? (string) $tier['label'] : null,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => Money::compare($left['threshold'], $right['threshold']));

        // A higher bracket never pays a lower rate than the one below it: that would punish the retailer for buying more.
        for ($index = 1; $index < count($rows); $index++) {
            if (Money::compare($rows[$index]['rate_percent'], $rows[$index - 1]['rate_percent']) < 0) {
                throw new GdoTradeTermsException('tier_rate_decreasing');
            }
        }

        return $rows;
    }

    private function assertWindow(GdoContract $contract, ?Carbon $start, ?Carbon $end): void
    {
        $from = $start ?? $contract->starts_on;
        $to = $end ?? $contract->ends_on;

        if ($to->lt($from)) {
            throw new GdoTradeTermsException('period_invalid');
        }

        if ($from->lt($contract->starts_on) || $to->gt($contract->ends_on)) {
            throw new GdoTradeTermsException('window_outside_contract');
        }
    }

    private function assertNoOverlap(GdoContract $contract, GdoScale $scale): void
    {
        if (! $scale->is_active) {
            return;
        }

        foreach (GdoScale::query()->where('contract_id', $contract->id)->where('is_active', true)->whereKeyNot($scale->id)->get() as $other) {
            if ($other->basis === $scale->basis && ScopeOverlap::overlaps($scale->windowStart($contract), $scale->windowEnd($contract), $this->scope($scale), $other->windowStart($contract), $other->windowEnd($contract), $this->scope($other))) {
                throw new GdoTradeTermsException('scale_overlap', 409, ['code' => $other->code]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(GdoScale $scale): array
    {
        return ['product_ids' => $scale->product_ids, 'category_ids' => $scale->category_ids, 'brand_ids' => $scale->brand_ids, 'delivery_point_ids' => $scale->delivery_point_ids];
    }

    private function hasSettled(GdoScale $scale): bool
    {
        return GdoSettlementLine::query()->where('source_type', SourceType::Scale->value)->where('source_id', $scale->id)->where('status', SettlementLineStatus::Settled->value)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function changesRebate(GdoScale $scale, array $data): bool
    {
        foreach (['tier_mode', 'basis', 'volume_mode', 'baseline_value', 'starts_on', 'ends_on', 'tiers', 'category_ids', 'brand_ids', 'product_ids', 'delivery_point_ids'] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }
}
