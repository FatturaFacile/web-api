<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffData;
use Modules\Payroll\Calculation\Swiss\WithholdingTaxTariffParser;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;

/**
 * Replaces the tariffs of the cantons and dates found in a file, for a company or, with no tenant, as generic data.
 */
final class WithholdingTaxTariffImporter
{
    private const int BatchSize = 1000;

    /**
     * @return int the rows imported
     */
    public function import(string $content, ?string $tenantId): int
    {
        $rows = WithholdingTaxTariffParser::parse($content);
        $keys = [];

        foreach ($rows as $row) {
            $keys[$row->canton.'|'.$row->validFrom->format('Y-m-d')] = [$row->canton, $row->validFrom->format('Y-m-d')];
        }

        DB::transaction(function () use ($rows, $keys, $tenantId): void {
            foreach ($keys as [$canton, $validFrom]) {
                PayrollWithholdingTaxTariff::query()->withoutGlobalScopes()
                    ->where('canton', $canton)
                    ->whereDate('valid_from', $validFrom)
                    ->when($tenantId === null, static fn ($query) => $query->whereNull('tenant_id'), static fn ($query) => $query->where('tenant_id', $tenantId))
                    ->delete();
            }

            $now = now();

            foreach (array_chunk($rows, self::BatchSize) as $batch) {
                PayrollWithholdingTaxTariff::query()->insert(array_map(static fn (WithholdingTaxTariffData $row): array => [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'canton' => $row->canton,
                    'tariff_code' => $row->tariffCode,
                    'valid_from' => $row->validFrom->format('Y-m-d'),
                    'income_from' => $row->incomeFrom,
                    'income_step' => $row->incomeStep,
                    'minimum_tax' => $row->minimumTax,
                    'rate_percent' => $row->ratePercent,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch));
            }
        });

        return count($rows);
    }
}
