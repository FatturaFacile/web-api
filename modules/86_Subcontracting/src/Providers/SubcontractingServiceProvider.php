<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;
use Modules\Subcontracting\Policies\SubcontractingPolicy;

/**
 * Mod 065 — Lavoro conto terzi: conto lavoro passivo / attivo, giacenze presso terzisti, DDT conto lavoro, scarico componenti
 * e registro Art. 53 DPR 633/72 (Issue #270).
 */
final class SubcontractingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([SubcontractingOrder::class, SubcontractingOrderComponent::class, SubcontractMaterialLedgerEntry::class] as $model) {
            Gate::policy($model, SubcontractingPolicy::class);
        }
    }
}
