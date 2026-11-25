<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\VanSalesAndFieldForce\Domain\Enums\LoadManifestStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;
use Modules\VanSalesAndFieldForce\Events\GlobalVanLoadDdtIssuedEvent;
use Modules\VanSalesAndFieldForce\Exceptions\VanSellingException;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * Handles morning van load planning, automatic historical weekday replenishment suggestions,
 * cold-chain departure checks, and emission of the self-addressed Global Van Load DDT
 * ("Tentata Vendita" per DPR 472/1996 & CM 249/E).
 */
final class VanLoadManifestService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DedicatedVanSectionalService $sectionals,
    ) {}

    /**
     * Suggests morning load quantities based on historical van sales for the same ISO weekday (1..7)
     * and route, subtracting current onboard residual stock.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestReplenishment(
        string $tenantId,
        VanWarehouse $van,
        int $weekdayIso,
        ?string $routeCode = null,
    ): array {
        $historicalVisits = VanVisitOutcome::query()
            ->where('tenant_id', $tenantId)
            ->where('van_warehouse_id', $van->id)
            ->whereNotNull('sale_lines')
            ->orderByDesc('visited_at')
            ->limit(120)
            ->get();

        $totalsByProduct = [];
        $samplesByProduct = [];

        foreach ($historicalVisits as $visit) {
            if ((int) $visit->visited_at->isoWeekday() !== $weekdayIso) {
                continue;
            }
            foreach ((array) $visit->sale_lines as $line) {
                $pid = (string) ($line['product_id'] ?? '');
                if ($pid === '') {
                    continue;
                }
                $qty = (float) ($line['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $totalsByProduct[$pid] = ($totalsByProduct[$pid] ?? 0.0) + $qty;
                $samplesByProduct[$pid] = ($samplesByProduct[$pid] ?? 0) + 1;
            }
        }

        // Fallback to active catalog products when no historical visits exist for that weekday yet
        if ($totalsByProduct === []) {
            $catalog = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sku')
                ->limit(8)
                ->get();

            $onboardMap = $this->onboardMap($van);
            $fallback = [];
            foreach ($catalog as $product) {
                $onboard = $onboardMap[(string) $product->id] ?? 0.0;
                $target = 20.0;
                $suggested = max(0.0, round($target - $onboard, 4));
                $fallback[] = [
                    'product_id' => (string) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit_measure' => $product->unit_measure ?? 'pz',
                    'historical_weekday_avg_qty' => $target,
                    'current_onboard_qty' => $onboard,
                    'suggested_load_qty' => $suggested,
                    'unit_price' => round((float) $product->sale_price, 4),
                    'basis' => $routeCode ? "weekday_{$weekdayIso}_baseline_{$routeCode}" : "weekday_{$weekdayIso}_baseline",
                ];
            }

            return $fallback;
        }

        $products = Product::query()->whereIn('id', array_keys($totalsByProduct))->get()->keyBy('id');
        $onboardMap = $this->onboardMap($van);
        $suggestions = [];

        foreach ($totalsByProduct as $pid => $totalQty) {
            /** @var Product|null $product */
            $product = $products->get($pid);
            if ($product === null) {
                continue;
            }
            $avgQty = round(($totalQty / max(1, $samplesByProduct[$pid])) * 1.10, 4);
            $onboard = $onboardMap[$pid] ?? 0.0;
            $suggested = max(0.0, round($avgQty - $onboard, 4));

            $suggestions[] = [
                'product_id' => $pid,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit_measure' => $product->unit_measure ?? 'pz',
                'historical_weekday_avg_qty' => $avgQty,
                'current_onboard_qty' => $onboard,
                'suggested_load_qty' => $suggested,
                'unit_price' => round((float) $product->sale_price, 4),
                'basis' => $routeCode ? "weekday_{$weekdayIso}_history_{$routeCode}" : "weekday_{$weekdayIso}_history",
            ];
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManifest(string $tenantId, array $data, ?string $userId = null): VanLoadManifest
    {
        /** @var VanWarehouse $van */
        $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($data['van_warehouse_id']);
        $loadDate = (string) ($data['load_date'] ?? now()->toDateString());
        $weekdayIso = (int) now()->parse($loadDate)->isoWeekday();

        $lines = $this->normalizeLines($tenantId, (array) ($data['lines'] ?? []));
        if ($lines === []) {
            throw new VanSellingException('manifest_lines_required', 422);
        }

        $totalPackages = 0;
        $totalWeight = 0.0;
        $totalNet = 0.0;
        foreach ($lines as $line) {
            $totalPackages += (int) ($line['packages_count'] ?? 1);
            $totalWeight += (float) ($line['gross_weight_kg'] ?? $line['quantity']);
            $totalNet += (float) ($line['line_value'] ?? 0);
        }

        $departureTemp = isset($data['departure_temp_celsius']) ? (float) $data['departure_temp_celsius'] : null;
        $coldCompliant = $this->checkColdChainCompliance($van, $departureTemp);
        $tenantName = (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?? 'FatturaFacile');

        return VanLoadManifest::create([
            'tenant_id' => $tenantId,
            'van_warehouse_id' => $van->id,
            'manifest_number' => (string) ($data['manifest_number'] ?? ('VLM-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)))),
            'load_date' => $loadDate,
            'weekday_iso' => $weekdayIso,
            'route_code' => $data['route_code'] ?? null,
            'depot_warehouse_id' => $data['depot_warehouse_id'] ?? $van->depot_warehouse_id,
            'global_ddt_causal' => (string) ($data['global_ddt_causal'] ?? 'Tentata Vendita'),
            'regulatory_reference' => (string) ($data['regulatory_reference'] ?? 'DPR 472/1996 & CM 249/E'),
            'self_addressed_company_name' => $tenantName,
            'departure_temp_celsius' => $departureTemp,
            'cold_chain_compliant' => $coldCompliant,
            'status' => LoadManifestStatus::Planned,
            'lines' => $lines,
            'replenishment_suggestions' => $this->suggestReplenishment($tenantId, $van, $weekdayIso, $data['route_code'] ?? null),
            'empties_loaded' => (array) ($data['empties_loaded'] ?? []),
            'total_packages' => $totalPackages,
            'total_gross_weight_kg' => round($totalWeight, 3),
            'total_net_value' => round($totalNet, 2),
            'currency' => (string) ($data['currency'] ?? 'EUR'),
            'created_by' => $userId,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Issues the self-addressed Global Van Load DDT ("Tentata Vendita" per DPR 472/1996 & CM 249/E),
     * moves stock from central depot to the mobile van warehouse, and updates the van's onboard snapshot.
     */
    public function issueGlobalLoadDdt(string $tenantId, VanLoadManifest $manifest): VanLoadManifest
    {
        if ($manifest->status !== LoadManifestStatus::Planned) {
            throw new VanSellingException('manifest_already_dispatched', 422);
        }

        /** @var VanWarehouse $van */
        $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($manifest->van_warehouse_id);

        if ($van->has_cold_chain && ! $manifest->cold_chain_compliant) {
            throw new VanSellingException('cold_chain_out_of_range', 422);
        }

        return DB::transaction(function () use ($tenantId, $manifest, $van): VanLoadManifest {
            $allocation = $this->sectionals->allocateNext($van, VanFiscalDocumentType::DeliveryNoteDdt);
            $tenant = Tenant::query()->find($tenantId);
            $selfName = $tenant !== null ? ($tenant->legal_name ?? $tenant->name) : 'Company Self';
            $selfContact = $this->resolveOrCreateSelfContact($tenantId, $selfName);

            $depotWarehouse = $manifest->depot_warehouse_id
                ? Warehouse::query()->where('tenant_id', $tenantId)->find($manifest->depot_warehouse_id)
                : $this->stock->defaultWarehouse();
            $vanWarehouseModel = $van->warehouse_id
                ? Warehouse::query()->where('tenant_id', $tenantId)->find($van->warehouse_id)
                : null;

            $ddt = DeliveryNote::create([
                'tenant_id' => $tenantId,
                'contact_id' => $selfContact->id,
                'warehouse_id' => $depotWarehouse?->id,
                'origin_warehouse_id' => $depotWarehouse?->id,
                'destination_warehouse_id' => $vanWarehouseModel?->id,
                'delivery_number' => $allocation['document_number'],
                'date' => $manifest->load_date->toDateString(),
                'status' => 'issued',
                'transport_reason_code' => 'tentata_vendita',
                'reason' => $manifest->global_ddt_causal.' ('.$manifest->regulatory_reference.') - '.$van->license_plate,
                'packages_count' => $manifest->total_packages,
                'gross_weight_kg' => $manifest->total_gross_weight_kg,
                'show_prices' => false,
                'fiscal_year' => $van->fiscal_year,
                'sequential_number' => $allocation['sequential_number'],
                'issued_at' => now(),
                'notes' => 'Global Van Load DDT (Mod 233) - '.$van->license_plate,
            ]);

            $snapshot = $this->indexSnapshotByProductLot((array) ($van->current_stock_snapshot ?? []));

            foreach ((array) $manifest->lines as $line) {
                $pid = (string) $line['product_id'];
                $qty = (float) $line['quantity'];
                $lot = (string) ($line['lot_number'] ?? '');

                DeliveryNoteItem::create([
                    'tenant_id' => $tenantId,
                    'delivery_note_id' => $ddt->id,
                    'product_id' => $pid,
                    'description' => (string) ($line['description'] ?? 'Item'),
                    'quantity' => $qty,
                    'unit_price' => (float) ($line['unit_price'] ?? 0),
                    'line_total' => (float) ($line['line_value'] ?? 0),
                ]);

                if ($depotWarehouse !== null) {
                    $this->stock->record($pid, 'out', $qty, $ddt, $depotWarehouse, (float) ($line['unit_cost'] ?? 0), 'Global Van Load DDT '.$allocation['document_number']);
                }
                if ($vanWarehouseModel !== null) {
                    $this->stock->record($pid, 'in', $qty, $ddt, $vanWarehouseModel, (float) ($line['unit_cost'] ?? 0), 'Loaded on van '.$van->license_plate);
                }

                $key = $pid.'|'.$lot;
                if (! isset($snapshot[$key])) {
                    $snapshot[$key] = [
                        'product_id' => $pid,
                        'sku' => $line['sku'] ?? '',
                        'description' => $line['description'] ?? '',
                        'lot_number' => $lot !== '' ? $lot : null,
                        'expiry_date' => $line['expiry_date'] ?? null,
                        'unit_measure' => $line['unit_measure'] ?? 'pz',
                        'is_variable_weight' => (bool) ($line['is_variable_weight'] ?? false),
                        'unit_price' => (float) ($line['unit_price'] ?? 0),
                        'quantity_onboard' => 0.0,
                    ];
                }
                $snapshot[$key]['quantity_onboard'] = round((float) $snapshot[$key]['quantity_onboard'] + $qty, 4);
            }

            $van->current_stock_snapshot = array_values($snapshot);
            $van->status = VanWarehouseStatus::OnRoute;
            $van->sync_version = (int) $van->sync_version + 1;
            $van->save();

            $manifest->global_ddt_id = (string) $ddt->id;
            $manifest->global_ddt_number = $allocation['document_number'];
            $manifest->status = LoadManifestStatus::DdtIssued;
            $manifest->dispatched_at = now();
            $manifest->save();

            event(new GlobalVanLoadDdtIssuedEvent(
                manifestId: (string) $manifest->id,
                tenantId: $tenantId,
                vanWarehouseId: (string) $van->id,
                globalDdtNumber: $allocation['document_number'],
                totalNetValue: (float) $manifest->total_net_value,
            ));

            return $manifest->fresh(['vanWarehouse']) ?? $manifest;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(string $tenantId, array $rawLines): array
    {
        $productIds = array_values(array_filter(array_map(static fn (array $l): string => (string) ($l['product_id'] ?? ''), $rawLines)));
        $products = Product::query()->where('tenant_id', $tenantId)->whereIn('id', $productIds)->get()->keyBy('id');

        $normalized = [];
        foreach ($rawLines as $line) {
            $pid = (string) ($line['product_id'] ?? '');
            /** @var Product|null $product */
            $product = $products->get($pid);
            $qty = round((float) ($line['quantity'] ?? 0), 4);
            if ($product === null || $qty <= 0) {
                continue;
            }
            $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : (float) $product->sale_price;
            $normalized[] = [
                'product_id' => $pid,
                'sku' => $product->sku,
                'description' => (string) ($line['description'] ?? $product->name),
                'unit_measure' => (string) ($line['unit_measure'] ?? $product->unit_measure ?? 'pz'),
                'quantity' => $qty,
                'lot_number' => $line['lot_number'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'is_variable_weight' => (bool) ($line['is_variable_weight'] ?? false),
                'packages_count' => (int) ($line['packages_count'] ?? 1),
                'gross_weight_kg' => round((float) ($line['gross_weight_kg'] ?? $qty), 3),
                'unit_cost' => round((float) $product->cost_price, 4),
                'unit_price' => round($unitPrice, 4),
                'line_value' => round($unitPrice * $qty, 2),
            ];
        }

        return $normalized;
    }

    private function checkColdChainCompliance(VanWarehouse $van, ?float $temp): bool
    {
        if (! $van->has_cold_chain || $temp === null) {
            return true;
        }
        $min = $van->min_temp_celsius !== null ? (float) $van->min_temp_celsius : -30.0;
        $max = $van->max_temp_celsius !== null ? (float) $van->max_temp_celsius : 10.0;

        return $temp >= $min && $temp <= $max;
    }

    /**
     * @return array<string, float>
     */
    private function onboardMap(VanWarehouse $van): array
    {
        $map = [];
        foreach ((array) ($van->current_stock_snapshot ?? []) as $row) {
            $pid = (string) ($row['product_id'] ?? '');
            if ($pid !== '') {
                $map[$pid] = ($map[$pid] ?? 0.0) + (float) ($row['quantity_onboard'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexSnapshotByProductLot(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $pid = (string) ($row['product_id'] ?? '');
            $lot = (string) ($row['lot_number'] ?? '');
            if ($pid !== '') {
                $indexed[$pid.'|'.$lot] = $row;
            }
        }

        return $indexed;
    }

    private function resolveOrCreateSelfContact(string $tenantId, string $companyName): Contact
    {
        $existing = Contact::query()
            ->where('tenant_id', $tenantId)
            ->where('company_name', $companyName)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Contact::create([
            'tenant_id' => $tenantId,
            'type' => 'customer',
            'company_name' => $companyName,
            'country' => 'IT',
        ]);
    }
}
