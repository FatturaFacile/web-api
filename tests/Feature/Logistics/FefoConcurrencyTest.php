<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Symfony\Component\Process\Process;
use Tests\Support\CommitsToDatabase;

require_once __DIR__.'/fixtures.php';

/**
 * Real concurrency needs rows other connections can see, so this file commits and cleans up after itself.
 */
uses(RefreshDatabase::class, CommitsToDatabase::class);

afterEach(function (): void {
    $tenantId = $this->tenant->id;

    foreach (['stock_reservation_allocations', 'stock_reservations', 'stock_quants', 'stock_lots', 'stock_items', 'stock_item_categories', 'warehouse_bins', 'warehouse_racks', 'warehouse_aisles', 'warehouse_zones', 'warehouses'] as $table) {
        DB::table($table)->where('tenant_id', $tenantId)->delete();
    }

    DB::table('tenants')->where('id', $tenantId)->delete();
});

test('parallel processes never oversell a lot and keep the fefo order', function (): void {
    $this->tenant = logisticsTenant('Concorrenza FEFO');
    $warehouse = logisticsWarehouse();
    $item = logisticsItem(['picking_strategy' => 'fefo']);
    $early = logisticsLot($item, ['expiration_date' => now()->addDays(5)]);
    $late = logisticsLot($item, ['expiration_date' => now()->addDays(50)]);
    $earlyQuant = logisticsQuant(logisticsBin($warehouse, bin: 'B1'), $item, '6', $early);
    $lateQuant = logisticsQuant(logisticsBin($warehouse, bin: 'B2'), $item, '9', $late);

    app(TenantContext::class)->clear();

    $startAt = microtime(true) + 12;
    $processes = [];

    foreach (range(1, 6) as $ignored) {
        $process = new Process(
            [PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/reserve_stock_worker.php'), (string) $this->tenant->id, (string) $item->id, (string) $warehouse->id, '4', (string) $startAt],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => (string) config('database.connections.pgsql.database')],
            timeout: 90,
        );
        $process->start();
        $processes[] = $process;
    }

    $outcomes = [];
    foreach ($processes as $process) {
        $process->wait();
        $outcomes[] = trim($process->getOutput());
    }

    $reserved = array_filter($outcomes, static fn (string $line): bool => $line === 'reserved');
    $refused = array_filter($outcomes, static fn (string $line): bool => $line === 'refused');

    expect(count($reserved))->toBe(3, implode(' | ', $outcomes).' '.implode(' ', array_map(static fn (Process $process): string => $process->getErrorOutput(), $processes)))
        ->and(count($refused))->toBe(3);

    app(TenantContext::class)->setTenant(Tenant::findOrFail($this->tenant->id));

    expect((string) StockQuant::findOrFail($earlyQuant->id)->quantity_reserved)->toBe('6.0000')
        ->and((string) StockQuant::findOrFail($lateQuant->id)->quantity_reserved)->toBe('6.0000')
        ->and(StockReservation::query()->where('status', 'active')->count())->toBe(3)
        ->and(DB::table('stock_reservation_allocations')->where('tenant_id', $this->tenant->id)->sum('quantity'))->toEqual(12);
});
