<?php

declare(strict_types=1);

use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\AssetManagement\Models\AssetAssignment;
use Modules\AssetManagement\Models\MaintenanceActivity;

test('asset assignments and maintenance activities are filtered by the tenant scope', function (string $model): void {
    $model::query();

    expect($model::hasGlobalScope(TenantScope::class))->toBeTrue();
})->with([
    'asset assignment' => AssetAssignment::class,
    'maintenance activity' => MaintenanceActivity::class,
]);
