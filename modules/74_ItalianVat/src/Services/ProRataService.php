<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\VatProRataRate;

/**
 * Pro-rata of deduction (art. 19 c. 5 and 19-bis DPR 633/1972): who carries out both operations with and without the right to
 * deduct deducts the input VAT of the common purchases only in proportion to the first. The percentage is the ratio between
 * the operations that give the right to deduct and all the operations of the year, rounded up to the next whole number.
 * During the year the percentage of the previous year is used (provisional); the year-end adjusts it (definitive).
 */
final class ProRataService
{
    /**
     * The percentage, rounded up to the next unit. Without operations nothing was exempt, so everything is deductible.
     */
    public function percentage(string $deductibleOperations, string $totalOperations): int
    {
        if (! Decimal::isPositive($totalOperations)) {
            return 100;
        }

        if (Decimal::isNegative($deductibleOperations) || Decimal::compare($deductibleOperations, $totalOperations) > 0) {
            throw ItalianVatException::invalid('pro_rata_operations_inconsistent');
        }

        $ratio = Decimal::div(Decimal::mul($deductibleOperations, '100'), $totalOperations);
        $whole = (int) bcadd($ratio, '0', 0);

        return min(100, bccomp($ratio, (string) $whole, 8) > 0 ? $whole + 1 : $whole);
    }

    /**
     * The deductible part of an amount of VAT; the rest is the non-deductible VAT (cost).
     *
     * @return array{deductible: string, non_deductible: string}
     */
    public function split(string $vat, int|string $percentage): array
    {
        $deductible = Decimal::round2(Decimal::div(Decimal::mul($vat, (string) $percentage), '100'));

        return ['deductible' => $deductible, 'non_deductible' => Decimal::round2(Decimal::sub($vat, $deductible))];
    }

    public function rateOf(string $tenantId, int $year): ?VatProRataRate
    {
        return VatProRataRate::query()->where('tenant_id', $tenantId)->where('year', $year)->first();
    }

    /**
     * The percentage in force for a year: the definitive one when the year is closed, else the provisional one, else the
     * one of the previous year, else 100.
     */
    public function percentageFor(string $tenantId, int $year): int
    {
        $rate = VatProRataRate::query()->where('tenant_id', $tenantId)->where('year', $year)->first();

        if ($rate !== null) {
            return $rate->is_definitive && $rate->definitive_percentage !== null ? $rate->definitive_percentage : $rate->provisional_percentage;
        }

        $previous = VatProRataRate::query()->where('tenant_id', $tenantId)->where('year', $year - 1)->first();

        return $previous !== null ? ($previous->definitive_percentage ?? $previous->provisional_percentage) : 100;
    }

    /**
     * Records the operations of a year and computes the percentage. With $definitive the year is closed and the percentage
     * becomes the definitive one; the provisional one is kept for the audit trail.
     */
    public function record(string $tenantId, int $year, string $deductibleOperations, string $totalOperations, bool $definitive): VatProRataRate
    {
        $percentage = $this->percentage($deductibleOperations, $totalOperations);
        $existing = VatProRataRate::query()->where('tenant_id', $tenantId)->where('year', $year)->first();

        if ($existing?->is_definitive) {
            throw ItalianVatException::locked('pro_rata_year_closed', ['year' => $year]);
        }

        $attributes = ['deductible_operations' => $deductibleOperations, 'total_operations' => $totalOperations];

        if ($definitive) {
            $attributes += ['definitive_percentage' => $percentage, 'is_definitive' => true];
        } else {
            $attributes += ['provisional_percentage' => $percentage];
        }

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return VatProRataRate::create($attributes + ['tenant_id' => $tenantId, 'year' => $year, 'provisional_percentage' => $percentage]);
    }
}
