<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use Modules\BondedWarehouses\Models\CustomsDischargeMovement;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;
use Modules\BondedWarehouses\Policies\BondedWarehousesPolicy;

/**
 * Mod 353 — private customs warehouses (CDU 7100 / Swiss OZL and Zollfreilager), IVA warehouses (Art. 50-bis DL 331/93) and AEO (Issue #599).
 */
final class BondedWarehousesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([CustomsWarehouseAuthorization::class, BondedMrnLot::class, CustomsDischargeMovement::class, CguLedgerEntry::class, VatDepotRegisterEntry::class, AeoComplianceAudit::class] as $model) {
            Gate::policy($model, BondedWarehousesPolicy::class);
        }
    }
}
