<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\SwissVat\Domain\Enums\InputTaxAdjustmentType;
use Modules\SwissVat\Exceptions\SwissVatException;
use Modules\SwissVat\Models\SwissVatInputTaxAdjustment;

/**
 * LIVA Art. 30-33 input tax adjustment engine:
 * - Autoconsumo / Eigenverbrauch (Art. 31 LIVA -> Box 415)
 * - Degrassazione / Einlageentsteuerung (Art. 32 LIVA -> Box 410)
 * - Mixed-use reduction / Vorsteuerkürzung (Art. 30 LIVA -> Box 415)
 * - Subsidies / Subventionen (Art. 33(2) LIVA -> Box 420)
 */
final class InputTaxAdjustmentService
{
    public const int MOVABLE_USEFUL_LIFE_YEARS = 5;

    public const int REAL_ESTATE_USEFUL_LIFE_YEARS = 20;

    /**
     * @return Collection<int, SwissVatInputTaxAdjustment>
     */
    public function list(string $tenantId, ?int $fiscalYear = null, ?string $status = null): Collection
    {
        return SwissVatInputTaxAdjustment::query()
            ->where('tenant_id', $tenantId)
            ->when($fiscalYear !== null, static fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($status !== null && $status !== '', static fn ($q) => $q->where('status', $status))
            ->orderByDesc('effective_date')
            ->get();
    }

    /**
     * Computes the adjustment amount from residual asset value (LIVA Art. 31/32: 20%/yr movable, 5%/yr real estate)
     * or mixed-use / subsidy ratio when a direct amount is not supplied.
     */
    public function computeAdjustmentAmount(
        InputTaxAdjustmentType $type,
        ?string $directAmount,
        ?string $originalCostChf,
        ?string $originalVatRate,
        ?int $yearsElapsed,
        ?string $assetCategory,
        ?string $privateOrExemptRatio,
    ): string {
        if ($directAmount !== null && SwissVatDecimal::isPositive($directAmount)) {
            return SwissVatDecimal::round2($directAmount);
        }

        if ($originalCostChf === null || ! SwissVatDecimal::isPositive($originalCostChf)) {
            throw SwissVatException::invalid('adjustment_amount_required');
        }

        $rate = $originalVatRate ?? '8.10';
        $originalVat = SwissVatDecimal::div(SwissVatDecimal::mul($originalCostChf, $rate), '100');

        if ($type === InputTaxAdjustmentType::MixedUseReduction || $type === InputTaxAdjustmentType::SubsidyReduction) {
            $ratio = $privateOrExemptRatio ?? '0';

            return SwissVatDecimal::round2(SwissVatDecimal::div(SwissVatDecimal::mul($originalVat, $ratio), '100'));
        }

        $usefulLife = $assetCategory === 'real_estate'
            ? self::REAL_ESTATE_USEFUL_LIFE_YEARS
            : self::MOVABLE_USEFUL_LIFE_YEARS;
        $elapsed = max(0, $yearsElapsed ?? 0);
        $remainingYears = max(0, $usefulLife - $elapsed);

        if ($remainingYears === 0) {
            return '0.00';
        }

        $fraction = SwissVatDecimal::div((string) $remainingYears, (string) $usefulLife);
        $ratioFactor = $privateOrExemptRatio !== null && SwissVatDecimal::isPositive($privateOrExemptRatio)
            ? SwissVatDecimal::div($privateOrExemptRatio, '100')
            : '1';

        return SwissVatDecimal::round2(SwissVatDecimal::mul(SwissVatDecimal::mul($originalVat, $fraction), $ratioFactor));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, ?string $userId, array $data): SwissVatInputTaxAdjustment
    {
        $type = InputTaxAdjustmentType::from((string) $data['adjustment_type']);
        $effectiveDate = CarbonImmutable::parse((string) $data['effective_date']);

        $amount = $this->computeAdjustmentAmount(
            type: $type,
            directAmount: isset($data['adjustment_amount_chf']) ? (string) $data['adjustment_amount_chf'] : null,
            originalCostChf: isset($data['original_cost_chf']) ? (string) $data['original_cost_chf'] : null,
            originalVatRate: isset($data['original_vat_rate']) ? (string) $data['original_vat_rate'] : null,
            yearsElapsed: isset($data['years_elapsed']) ? (int) $data['years_elapsed'] : null,
            assetCategory: isset($data['asset_category']) ? (string) $data['asset_category'] : null,
            privateOrExemptRatio: isset($data['private_or_exempt_ratio']) ? (string) $data['private_or_exempt_ratio'] : null,
        );

        return SwissVatInputTaxAdjustment::create([
            'tenant_id' => $tenantId,
            'adjustment_type' => $type->value,
            'afc_box' => $type->afcBox(),
            'effective_date' => $effectiveDate->toDateString(),
            'fiscal_year' => (int) $effectiveDate->format('Y'),
            'asset_category' => $data['asset_category'] ?? 'general',
            'description' => (string) $data['description'],
            'reference_document' => $data['reference_document'] ?? null,
            'original_cost_chf' => $data['original_cost_chf'] ?? null,
            'original_vat_rate' => $data['original_vat_rate'] ?? null,
            'years_elapsed' => $data['years_elapsed'] ?? null,
            'private_or_exempt_ratio' => $data['private_or_exempt_ratio'] ?? null,
            'adjustment_amount_chf' => $amount,
            'legal_basis' => $type->legalBasis(),
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $tenantId, string $id, array $data): SwissVatInputTaxAdjustment
    {
        $adjustment = $this->findForTenant($tenantId, $id);

        if ($adjustment->status === 'applied') {
            throw SwissVatException::locked('adjustment_already_applied');
        }

        $type = isset($data['adjustment_type'])
            ? InputTaxAdjustmentType::from((string) $data['adjustment_type'])
            : InputTaxAdjustmentType::from((string) $adjustment->adjustment_type);

        $effectiveDate = isset($data['effective_date'])
            ? CarbonImmutable::parse((string) $data['effective_date'])
            : $adjustment->effective_date;

        $amount = $this->computeAdjustmentAmount(
            type: $type,
            directAmount: array_key_exists('adjustment_amount_chf', $data) ? ($data['adjustment_amount_chf'] !== null ? (string) $data['adjustment_amount_chf'] : null) : (string) $adjustment->adjustment_amount_chf,
            originalCostChf: array_key_exists('original_cost_chf', $data) ? ($data['original_cost_chf'] !== null ? (string) $data['original_cost_chf'] : null) : ($adjustment->original_cost_chf !== null ? (string) $adjustment->original_cost_chf : null),
            originalVatRate: array_key_exists('original_vat_rate', $data) ? ($data['original_vat_rate'] !== null ? (string) $data['original_vat_rate'] : null) : ($adjustment->original_vat_rate !== null ? (string) $adjustment->original_vat_rate : null),
            yearsElapsed: array_key_exists('years_elapsed', $data) ? ($data['years_elapsed'] !== null ? (int) $data['years_elapsed'] : null) : $adjustment->years_elapsed,
            assetCategory: array_key_exists('asset_category', $data) ? ($data['asset_category'] !== null ? (string) $data['asset_category'] : null) : $adjustment->asset_category,
            privateOrExemptRatio: array_key_exists('private_or_exempt_ratio', $data) ? ($data['private_or_exempt_ratio'] !== null ? (string) $data['private_or_exempt_ratio'] : null) : ($adjustment->private_or_exempt_ratio !== null ? (string) $adjustment->private_or_exempt_ratio : null),
        );

        $adjustment->update([
            'adjustment_type' => $type->value,
            'afc_box' => $type->afcBox(),
            'effective_date' => $effectiveDate->toDateString(),
            'fiscal_year' => (int) $effectiveDate->format('Y'),
            'asset_category' => $data['asset_category'] ?? $adjustment->asset_category,
            'description' => $data['description'] ?? $adjustment->description,
            'reference_document' => $data['reference_document'] ?? $adjustment->reference_document,
            'original_cost_chf' => $data['original_cost_chf'] ?? $adjustment->original_cost_chf,
            'original_vat_rate' => $data['original_vat_rate'] ?? $adjustment->original_vat_rate,
            'years_elapsed' => $data['years_elapsed'] ?? $adjustment->years_elapsed,
            'private_or_exempt_ratio' => $data['private_or_exempt_ratio'] ?? $adjustment->private_or_exempt_ratio,
            'adjustment_amount_chf' => $amount,
            'legal_basis' => $type->legalBasis(),
            'notes' => $data['notes'] ?? $adjustment->notes,
        ]);

        return $adjustment;
    }

    public function approve(string $tenantId, string $id, ?string $userId): SwissVatInputTaxAdjustment
    {
        $adjustment = $this->findForTenant($tenantId, $id);

        if ($adjustment->status === 'applied') {
            throw SwissVatException::locked('adjustment_already_applied');
        }

        $adjustment->update([
            'status' => 'approved',
            'approved_by' => $userId,
        ]);

        return $adjustment;
    }

    public function delete(string $tenantId, string $id): void
    {
        $adjustment = $this->findForTenant($tenantId, $id);

        if ($adjustment->status === 'applied') {
            throw SwissVatException::locked('adjustment_already_applied');
        }

        $adjustment->delete();
    }

    /**
     * Summarizes approved/applied adjustments for a reporting period into AFC Boxes 410, 415, 420.
     *
     * @return array{box_410: string, box_415: string, box_420: string, entries: Collection<int, SwissVatInputTaxAdjustment>}
     */
    public function periodTotals(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = SwissVatInputTaxAdjustment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['approved', 'applied'])
            ->whereDate('effective_date', '>=', $from->toDateString())
            ->whereDate('effective_date', '<=', $to->toDateString())
            ->orderBy('effective_date')
            ->get();

        $box410 = '0.00';
        $box415 = '0.00';
        $box420 = '0.00';

        foreach ($entries as $entry) {
            $amt = (string) $entry->adjustment_amount_chf;
            match ($entry->afc_box) {
                '410' => $box410 = SwissVatDecimal::add($box410, $amt),
                '415' => $box415 = SwissVatDecimal::add($box415, $amt),
                '420' => $box420 = SwissVatDecimal::add($box420, $amt),
                default => null,
            };
        }

        return [
            'box_410' => SwissVatDecimal::round2($box410),
            'box_415' => SwissVatDecimal::round2($box415),
            'box_420' => SwissVatDecimal::round2($box420),
            'entries' => $entries,
        ];
    }

    private function findForTenant(string $tenantId, string $id): SwissVatInputTaxAdjustment
    {
        $adjustment = SwissVatInputTaxAdjustment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();

        if ($adjustment === null) {
            throw SwissVatException::notFound('adjustment_not_found');
        }

        return $adjustment;
    }
}
