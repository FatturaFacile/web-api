<?php

declare(strict_types=1);

/**
 * One contender of the FEFO concurrency test: boots the application, waits for the agreed instant and reserves.
 * Prints `reserved` or `refused`.
 */

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Services\StockReservationService;

require __DIR__.'/../../vendor/autoload.php';

$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

[, $tenantId, $itemId, $warehouseId, $quantity, $startAt] = $argv;

app(TenantContext::class)->setTenant(Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId));

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    app(StockReservationService::class)->allocate(new StockReservationDTO($itemId, $warehouseId, 'sales_orders', (string) Str::uuid7(), (float) $quantity));
    echo 'reserved';
} catch (StockRuleViolation) {
    echo 'refused';
}
