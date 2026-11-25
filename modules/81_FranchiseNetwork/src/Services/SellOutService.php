<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Illuminate\Support\Facades\DB;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\RoyaltyStatement;
use Modules\FranchiseNetwork\Models\SellOutReport;

/**
 * Net sell-out of the stores, as recorded by their POS and fiscal printers (registratori telematici). One report per store and
 * month; net = gross - returns - discounts. A month already invoiced is locked.
 */
final class SellOutService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(FranchiseStore $store, string $period, array $data, ?string $userId): SellOutReport
    {
        BillingPeriod::assert($period);

        $locked = RoyaltyStatement::query()->where('store_id', $store->id)->where('period', $period)->where('status', 'invoiced')->exists();

        if ($locked) {
            throw new FranchiseNetworkException('sell_out_locked', 409);
        }

        $gross = Dec::of($data['gross_sales'] ?? 0);
        $returns = Dec::of($data['returns'] ?? 0);
        $discounts = Dec::of($data['discounts'] ?? 0);
        $net = Dec::sub(Dec::sub($gross, $returns), $discounts);

        if (Dec::cmp($net, 0) < 0) {
            throw new FranchiseNetworkException('sell_out_negative');
        }

        return DB::transaction(function () use ($store, $period, $data, $gross, $returns, $discounts, $net, $userId): SellOutReport {
            $report = SellOutReport::query()->where('store_id', $store->id)->where('period', $period)->first();
            $values = [
                'gross_sales' => Dec::round($gross),
                'returns' => Dec::round($returns),
                'discounts' => Dec::round($discounts),
                'net_sales' => Dec::round($net),
                'tickets_count' => (int) ($data['tickets_count'] ?? 0),
                'visitors_count' => (int) ($data['visitors_count'] ?? 0),
                'cost_of_goods_sold' => Dec::round($data['cost_of_goods_sold'] ?? 0),
                'average_inventory_value' => Dec::round($data['average_inventory_value'] ?? 0),
                'source' => $data['source'] ?? 'pos',
                'pos_reference' => $data['pos_reference'] ?? null,
                'submitted_at' => now(),
            ];

            if ($report === null) {
                return SellOutReport::query()->create([...$values, 'store_id' => $store->id, 'period' => $period, 'created_by' => $userId])->refresh();
            }

            $report->update([...$values, 'updated_by' => $userId]);

            return $report->refresh();
        });
    }

    /**
     * Imports a month of several stores at once (POS consolidation file already parsed to rows keyed by store code).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, rejected: list<array{store_code: string, code: string}>}
     */
    public function import(string $period, array $rows, ?string $userId): array
    {
        $stores = FranchiseStore::query()->get()->keyBy('code');
        $imported = 0;
        $rejected = [];

        foreach ($rows as $row) {
            $store = $stores->get((string) ($row['store_code'] ?? ''));

            if ($store === null) {
                $rejected[] = ['store_code' => (string) ($row['store_code'] ?? ''), 'code' => 'store_unknown'];

                continue;
            }

            try {
                $this->upsert($store, $period, $row, $userId);
                $imported++;
            } catch (FranchiseNetworkException $exception) {
                $rejected[] = ['store_code' => $store->code, 'code' => $exception->errorCode()];
            }
        }

        return ['imported' => $imported, 'rejected' => $rejected];
    }
}
