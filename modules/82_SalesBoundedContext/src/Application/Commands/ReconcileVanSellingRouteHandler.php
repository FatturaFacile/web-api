<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\VanSellingRouteReconciledEvent;
use Modules\SalesBoundedContext\Models\SbcVanSellingRoute;

final readonly class ReconcileVanSellingRouteHandler
{
    public function handle(ReconcileVanSellingRouteCommand $command): SbcVanSellingRoute
    {
        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));

        $loadedQty = round((float) ($data['loaded_stock_qty'] ?? 100), 3);
        $soldQty = round((float) ($data['sold_stock_qty'] ?? 78), 3);
        $returnedQty = round((float) ($data['returned_unsold_qty'] ?? 22), 3);
        $stockVariance = round($loadedQty - ($soldQty + $returnedQty), 3);

        $expectedCash = DecimalMoney::of((float) ($data['expected_cash_amount'] ?? 2450), $currency);
        $collectedCash = DecimalMoney::of((float) ($data['collected_cash_amount'] ?? 2450), $currency);
        $electronicPay = DecimalMoney::of((float) ($data['electronic_payment_amount'] ?? 1890), $currency);
        $cashDiscrepancy = $collectedCash->subtract($expectedCash);

        $isBalanced = abs($stockVariance) < 0.001 && abs($cashDiscrepancy->toFloat()) < 0.01;
        $reconciliationStatus = $isBalanced ? 'reconciled_balanced' : 'reconciled_discrepancy';

        $routeCode = (string) ($data['route_code'] ?? 'VAN-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6)));
        $sezionale = (string) ($data['document_series_sezionale'] ?? 'VAN-TI-01');

        $escposPayload = implode("\n", [
            '*** FATTURAFACILE VAN SELLING RECEIPT ***',
            "ROUTE: {$routeCode} | SERIES: {$sezionale}",
            'AGENT: '.(string) ($data['driver_agent_name'] ?? 'Field Agent'),
            "SOLD QTY: {$soldQty} | RETURNED: {$returnedQty}",
            "CASH: {$collectedCash->roundHalfUp()} {$currency} | CARD/TWINT: {$electronicPay->roundHalfUp()} {$currency}",
            "STATUS: {$reconciliationStatus}",
        ]);

        $route = DB::transaction(function () use (
            $command,
            $data,
            $routeCode,
            $sezionale,
            $loadedQty,
            $soldQty,
            $returnedQty,
            $stockVariance,
            $expectedCash,
            $collectedCash,
            $electronicPay,
            $cashDiscrepancy,
            $currency,
            $escposPayload,
            $reconciliationStatus,
        ): SbcVanSellingRoute {
            return SbcVanSellingRoute::query()->create([
                'tenant_id' => $command->tenantId,
                'route_code' => $routeCode,
                'driver_agent_name' => (string) ($data['driver_agent_name'] ?? 'Matteo Fontana'),
                'vehicle_plate' => (string) ($data['vehicle_plate'] ?? 'TI-448291'),
                'traveling_warehouse_code' => (string) ($data['traveling_warehouse_code'] ?? 'WH-VAN-01'),
                'document_series_sezionale' => $sezionale,
                'loaded_stock_qty' => $loadedQty,
                'sold_stock_qty' => $soldQty,
                'returned_unsold_qty' => $returnedQty,
                'stock_variance_qty' => $stockVariance,
                'expected_cash_amount' => $expectedCash->roundHalfUp(),
                'collected_cash_amount' => $collectedCash->roundHalfUp(),
                'electronic_payment_amount' => $electronicPay->roundHalfUp(),
                'cash_discrepancy_amount' => $cashDiscrepancy->roundHalfUp(),
                'currency' => $currency,
                'bluetooth_escpos_payload' => $escposPayload,
                'offline_sync_batch_id' => (string) ($data['offline_sync_batch_id'] ?? 'SYNC-'.now()->format('Ymd-His')),
                'reconciliation_status' => $reconciliationStatus,
            ]);
        });

        VanSellingRouteReconciledEvent::dispatch(
            $command->tenantId,
            (string) $route->id,
            $reconciliationStatus,
            $cashDiscrepancy->toFloat(),
        );

        return $route;
    }
}
