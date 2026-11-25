<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\VanSalesAndFieldForce\Domain\Enums\CollectionMethod;
use Modules\VanSalesAndFieldForce\Domain\Enums\LoadManifestStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\SettlementStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;
use Modules\VanSalesAndFieldForce\Events\EndOfDayVanSettlementCompletedEvent;
use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * End-of-day van settlement ("Quadratura Fine Giro"):
 * Morning Load - Sold - Returned/Damaged - Freebies = Remaining Van Stock,
 * with explicit per-item/lot choice to keep onboard for next day or unload to central warehouse,
 * empties reconciliation, cold-chain log summary, and cash/POS/TWINT/cheque deposit slip.
 */
final class VanEndOfDaySettlementService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        string $tenantId,
        string $vanWarehouseId,
        ?string $manifestId = null,
        ?string $settlementDate = null,
    ): array {
        /** @var VanWarehouse $van */
        $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($vanWarehouseId);
        $date = $settlementDate ?? now()->toDateString();

        $manifest = $manifestId
            ? VanLoadManifest::query()->where('tenant_id', $tenantId)->find($manifestId)
            : VanLoadManifest::query()
                ->where('tenant_id', $tenantId)
                ->where('van_warehouse_id', $van->id)
                ->whereDate('load_date', $date)
                ->orderByDesc('created_at')
                ->first();

        $visits = VanVisitOutcome::query()
            ->where('tenant_id', $tenantId)
            ->where('van_warehouse_id', $van->id)
            ->whereDate('visited_at', $date)
            ->get();

        $linesMap = [];
        $sourceLines = $manifest !== null ? $manifest->lines : ($van->current_stock_snapshot ?? []);
        foreach ((array) $sourceLines as $line) {
            $pid = (string) ($line['product_id'] ?? '');
            $lot = (string) ($line['lot_number'] ?? '');
            if ($pid === '') {
                continue;
            }
            $key = $pid.'|'.$lot;
            $loaded = (float) ($line['quantity'] ?? $line['quantity_onboard'] ?? 0);
            $linesMap[$key] = [
                'product_id' => $pid,
                'sku' => (string) ($line['sku'] ?? ''),
                'description' => (string) ($line['description'] ?? ''),
                'lot_number' => $lot !== '' ? $lot : null,
                'unit_measure' => (string) ($line['unit_measure'] ?? 'pz'),
                'loaded_qty' => round(($linesMap[$key]['loaded_qty'] ?? 0.0) + $loaded, 4),
                'sold_qty' => 0.0,
                'freebies_qty' => 0.0,
                'returned_damaged_qty' => 0.0,
            ];
        }

        $cash = 0.0;
        $pos = 0.0;
        $twint = 0.0;
        $cheque = 0.0;
        $bank = 0.0;
        $emptiesMap = [];

        foreach ($visits as $visit) {
            foreach ((array) ($visit->sale_lines ?? []) as $sline) {
                $pid = (string) ($sline['product_id'] ?? '');
                $lot = (string) ($sline['lot_number'] ?? '');
                $key = $pid.'|'.$lot;
                if (! isset($linesMap[$key])) {
                    $linesMap[$key] = [
                        'product_id' => $pid,
                        'sku' => (string) ($sline['sku'] ?? ''),
                        'description' => (string) ($sline['description'] ?? ''),
                        'lot_number' => $lot !== '' ? $lot : null,
                        'unit_measure' => (string) ($sline['unit_measure'] ?? 'pz'),
                        'loaded_qty' => 0.0,
                        'sold_qty' => 0.0,
                        'freebies_qty' => 0.0,
                        'returned_damaged_qty' => 0.0,
                    ];
                }
                if ((bool) ($sline['is_freebie'] ?? false)) {
                    $linesMap[$key]['freebies_qty'] = round($linesMap[$key]['freebies_qty'] + (float) ($sline['quantity'] ?? 0), 4);
                } else {
                    $linesMap[$key]['sold_qty'] = round($linesMap[$key]['sold_qty'] + (float) ($sline['quantity'] ?? 0), 4);
                }
            }

            foreach ((array) ($visit->return_lines ?? []) as $rline) {
                $pid = (string) ($rline['product_id'] ?? '');
                $lot = (string) ($rline['lot_number'] ?? '');
                $key = $pid.'|'.$lot;
                if (isset($linesMap[$key])) {
                    $linesMap[$key]['returned_damaged_qty'] = round($linesMap[$key]['returned_damaged_qty'] + (float) ($rline['quantity'] ?? 0), 4);
                }
            }

            foreach ((array) ($visit->empties_movements ?? []) as $emp) {
                $code = (string) ($emp['item_code'] ?? '');
                if ($code === '') {
                    continue;
                }
                if (! isset($emptiesMap[$code])) {
                    $emptiesMap[$code] = [
                        'item_code' => $code,
                        'item_name' => (string) ($emp['item_name'] ?? $code),
                        'delivered_qty' => 0,
                        'returned_qty' => 0,
                    ];
                }
                $emptiesMap[$code]['delivered_qty'] += (int) ($emp['delivered_qty'] ?? 0);
                $emptiesMap[$code]['returned_qty'] += (int) ($emp['returned_qty'] ?? 0);
            }

            $visitCollections = (array) ($visit->collections ?? []);
            if ($visitCollections !== []) {
                foreach ($visitCollections as $col) {
                    $amt = (float) ($col['amount'] ?? 0);
                    match ((string) ($col['method'] ?? 'cash')) {
                        CollectionMethod::PosCard->value => $pos += $amt,
                        CollectionMethod::Twint->value => $twint += $amt,
                        CollectionMethod::Cheque->value => $cheque += $amt,
                        CollectionMethod::BankTransfer->value => $bank += $amt,
                        default => $cash += $amt,
                    };
                }
            } elseif ((float) $visit->total_collected_amount > 0) {
                $amt = (float) $visit->total_collected_amount;
                match ((string) ($visit->payment_method ?? 'cash')) {
                    CollectionMethod::PosCard->value => $pos += $amt,
                    CollectionMethod::Twint->value => $twint += $amt,
                    CollectionMethod::Cheque->value => $cheque += $amt,
                    CollectionMethod::BankTransfer->value => $bank += $amt,
                    default => $cash += $amt,
                };
            }

            if ((float) $visit->collected_overdue_amount > 0) {
                $cash += (float) $visit->collected_overdue_amount;
            }
        }

        $reconciliationLines = [];
        foreach ($linesMap as $row) {
            $remaining = max(0.0, round($row['loaded_qty'] - $row['sold_qty'] - $row['freebies_qty'], 4));
            $reconciliationLines[] = [
                ...$row,
                'remaining_qty' => $remaining,
                'keep_onboard_qty' => $remaining,
                'unload_central_qty' => 0.0,
            ];
        }

        $tempReadings = array_map(
            static fn (array $e): float => (float) ($e['temp_celsius'] ?? 0),
            (array) ($van->temperature_log ?? [])
        );

        return [
            'van_warehouse_id' => (string) $van->id,
            'van_license_plate' => $van->license_plate,
            'van_load_manifest_id' => $manifest?->id !== null ? (string) $manifest->id : null,
            'settlement_date' => $date,
            'stock_reconciliation_lines' => $reconciliationLines,
            'empties_reconciliation' => array_values($emptiesMap),
            'cold_chain_summary' => [
                'has_cold_chain' => $van->has_cold_chain,
                'min_threshold_celsius' => $van->min_temp_celsius,
                'max_threshold_celsius' => $van->max_temp_celsius,
                'readings_count' => count($tempReadings),
                'min_recorded_celsius' => $tempReadings !== [] ? min($tempReadings) : $van->current_temp_celsius,
                'max_recorded_celsius' => $tempReadings !== [] ? max($tempReadings) : $van->current_temp_celsius,
                'alert_triggered' => $van->temp_alert_active,
            ],
            'collections_summary' => [
                'collected_cash_amount' => round($cash, 2),
                'collected_pos_card_amount' => round($pos, 2),
                'collected_twint_amount' => round($twint, 2),
                'collected_cheque_amount' => round($cheque, 2),
                'collected_bank_transfer_amount' => round($bank, 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function completeSettlement(string $tenantId, array $data, ?string $userId = null): VanEodSettlement
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): VanEodSettlement {
            /** @var VanWarehouse $van */
            $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($data['van_warehouse_id']);
            $preview = $this->preview($tenantId, (string) $van->id, $data['van_load_manifest_id'] ?? null, $data['settlement_date'] ?? null);

            $lines = (array) ($data['stock_reconciliation_lines'] ?? $preview['stock_reconciliation_lines']);
            $totLoaded = 0.0;
            $totSold = 0.0;
            $totDamaged = 0.0;
            $totFreebies = 0.0;
            $totRemaining = 0.0;
            $totKeep = 0.0;
            $totUnload = 0.0;
            $newOnboardSnapshot = [];

            $depotWh = $van->depot_warehouse_id
                ? Warehouse::query()->where('tenant_id', $tenantId)->find($van->depot_warehouse_id)
                : $this->stock->defaultWarehouse();
            $vanWh = $van->warehouse_id
                ? Warehouse::query()->where('tenant_id', $tenantId)->find($van->warehouse_id)
                : null;

            foreach ($lines as $idx => $line) {
                $loaded = (float) ($line['loaded_qty'] ?? 0);
                $sold = (float) ($line['sold_qty'] ?? 0);
                $damaged = (float) ($line['returned_damaged_qty'] ?? 0);
                $freebies = (float) ($line['freebies_qty'] ?? 0);
                $remaining = max(0.0, round($loaded - $sold - $freebies, 4));
                $keep = isset($line['keep_onboard_qty']) ? min($remaining, max(0.0, (float) $line['keep_onboard_qty'])) : $remaining;
                $unload = isset($line['unload_central_qty']) ? min($remaining - $keep, max(0.0, (float) $line['unload_central_qty'])) : round($remaining - $keep, 4);

                $lines[$idx]['remaining_qty'] = $remaining;
                $lines[$idx]['keep_onboard_qty'] = round($keep, 4);
                $lines[$idx]['unload_central_qty'] = round($unload, 4);

                $totLoaded += $loaded;
                $totSold += $sold;
                $totDamaged += $damaged;
                $totFreebies += $freebies;
                $totRemaining += $remaining;
                $totKeep += $keep;
                $totUnload += $unload;

                if ($keep > 0) {
                    $newOnboardSnapshot[] = [
                        'product_id' => $line['product_id'],
                        'sku' => $line['sku'] ?? '',
                        'description' => $line['description'] ?? '',
                        'lot_number' => $line['lot_number'] ?? null,
                        'unit_measure' => $line['unit_measure'] ?? 'pz',
                        'quantity_onboard' => round($keep, 4),
                    ];
                }

                if ($unload > 0) {
                    $pid = (string) $line['product_id'];
                    if ($vanWh !== null) {
                        $this->stock->record($pid, 'out', $unload, null, $vanWh, 0.0, 'EOD unload from van '.$van->license_plate);
                    }
                    if ($depotWh !== null) {
                        $this->stock->record($pid, 'in', $unload, null, $depotWh, 0.0, 'EOD return to central depot from '.$van->license_plate);
                    }
                }
            }

            $colSummary = $preview['collections_summary'];
            $openingFloat = round((float) ($data['opening_cash_float'] ?? 0), 2);
            $cash = round((float) ($data['collected_cash_amount'] ?? $colSummary['collected_cash_amount']), 2);
            $pos = round((float) ($data['collected_pos_card_amount'] ?? $colSummary['collected_pos_card_amount']), 2);
            $twint = round((float) ($data['collected_twint_amount'] ?? $colSummary['collected_twint_amount']), 2);
            $cheque = round((float) ($data['collected_cheque_amount'] ?? $colSummary['collected_cheque_amount']), 2);
            $bank = round((float) ($data['collected_bank_transfer_amount'] ?? $colSummary['collected_bank_transfer_amount']), 2);

            $expectedCash = round($openingFloat + $cash, 2);
            $countedCash = round((float) ($data['counted_cash_remittance'] ?? $expectedCash), 2);
            $discrepancy = round($countedCash - $expectedCash, 2);
            $totalRemittance = round($countedCash + $pos + $twint + $cheque + $bank, 2);

            $settlementNumber = (string) ($data['settlement_number'] ?? ('EOD-'.$van->code.'-'.now()->format('Ymd-His')));
            $depositSlipNumber = (string) ($data['deposit_slip_number'] ?? ('VERS-'.now()->format('Ymd').'-'.strtoupper(Str::random(4))));

            $settlement = VanEodSettlement::create([
                'tenant_id' => $tenantId,
                'van_warehouse_id' => $van->id,
                'van_load_manifest_id' => $preview['van_load_manifest_id'],
                'field_route_plan_id' => $data['field_route_plan_id'] ?? null,
                'settlement_number' => $settlementNumber,
                'settlement_date' => $preview['settlement_date'],
                'status' => SettlementStatus::Reconciled,
                'stock_reconciliation_lines' => $lines,
                'total_loaded_qty' => round($totLoaded, 4),
                'total_sold_qty' => round($totSold, 4),
                'total_returned_damaged_qty' => round($totDamaged, 4),
                'total_freebies_qty' => round($totFreebies, 4),
                'total_remaining_qty' => round($totRemaining, 4),
                'total_keep_onboard_qty' => round($totKeep, 4),
                'total_unload_central_qty' => round($totUnload, 4),
                'empties_reconciliation' => $data['empties_reconciliation'] ?? $preview['empties_reconciliation'],
                'cold_chain_summary' => $preview['cold_chain_summary'],
                'opening_cash_float' => $openingFloat,
                'collected_cash_amount' => $cash,
                'collected_pos_card_amount' => $pos,
                'collected_twint_amount' => $twint,
                'collected_cheque_amount' => $cheque,
                'collected_bank_transfer_amount' => $bank,
                'expected_cash_remittance' => $expectedCash,
                'counted_cash_remittance' => $countedCash,
                'cash_discrepancy_amount' => $discrepancy,
                'total_remittance_amount' => $totalRemittance,
                'deposit_slip_number' => $depositSlipNumber,
                'treasury_deposited' => true,
                'central_warehouse_unloaded' => $totUnload > 0,
                'currency' => (string) ($data['currency'] ?? 'EUR'),
                'reconciled_by' => $userId,
                'reconciled_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $van->current_stock_snapshot = $newOnboardSnapshot;
            $van->status = VanWarehouseStatus::Active;
            $van->sync_version = (int) $van->sync_version + 1;
            $van->save();

            if ($preview['van_load_manifest_id'] !== null) {
                VanLoadManifest::query()
                    ->whereKey($preview['van_load_manifest_id'])
                    ->update(['status' => LoadManifestStatus::Settled->value, 'closed_at' => now()]);
            }

            event(new EndOfDayVanSettlementCompletedEvent(
                settlementId: (string) $settlement->id,
                tenantId: $tenantId,
                vanWarehouseId: (string) $van->id,
                settlementNumber: $settlementNumber,
                totalRemittanceAmount: $totalRemittance,
            ));

            return $settlement->fresh(['vanWarehouse']) ?? $settlement;
        });
    }
}
