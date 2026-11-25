<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\SellOutSource;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;
use Modules\GdoTradeTerms\Models\GdoSellOutReport;
use Modules\GdoTradeTerms\Support\Money;

/**
 * Imports the POS sell-out the retailer reports. A row is identified by contract, store, product and period: sending the
 * same period again corrects it instead of duplicating it, so the sell-out contributions never count a sale twice.
 */
final class SellOutService
{
    /**
     * @param  list<array{delivery_point_id?: string|null, product_id: string, period_start: string, period_end: string, quantity: string|int|float, amount: string|int|float}>  $rows
     * @return array{created: int, updated: int}
     */
    public function import(GdoContract $contract, array $rows, string $source, ?string $userId): array
    {
        if ($contract->status === ContractStatus::Cancelled) {
            throw new GdoTradeTermsException('contract_locked', 409);
        }

        $source = SellOutSource::tryFrom($source) ?? SellOutSource::Manual;

        return DB::transaction(function () use ($contract, $rows, $source, $userId): array {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $this->assertRow($contract, $row);
                $pointId = $row['delivery_point_id'] ?? null;
                $key = md5(implode('|', [$contract->id, $pointId ?? '-', $row['product_id'], $row['period_start'], $row['period_end']]));
                $report = GdoSellOutReport::query()->where('natural_key', $key)->first();

                $values = [
                    'contract_id' => $contract->id,
                    'delivery_point_id' => $pointId,
                    'product_id' => $row['product_id'],
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'quantity' => Money::round((string) $row['quantity']),
                    'amount' => Money::round((string) $row['amount']),
                    'source' => $source->value,
                    'reported_at' => now(),
                    'updated_by' => $userId,
                ];

                if ($report === null) {
                    GdoSellOutReport::create([...$values, 'tenant_id' => $contract->tenant_id, 'natural_key' => $key, 'created_by' => $userId]);
                    $created++;
                } else {
                    $report->update($values);
                    $updated++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertRow(GdoContract $contract, array $row): void
    {
        if (Carbon::parse((string) $row['period_end'])->lt(Carbon::parse((string) $row['period_start']))) {
            throw new GdoTradeTermsException('period_invalid');
        }

        $productExists = DB::table('products')->where('tenant_id', $contract->tenant_id)->where('id', $row['product_id'])->whereNull('deleted_at')->exists();

        if (! $productExists) {
            throw new GdoTradeTermsException('product_not_found', 404);
        }

        if (! empty($row['delivery_point_id']) && GdoDeliveryPoint::query()->where('contact_id', $contract->contact_id)->whereKey($row['delivery_point_id'])->doesntExist()) {
            throw new GdoTradeTermsException('assignment_target_not_found', 404);
        }
    }
}
