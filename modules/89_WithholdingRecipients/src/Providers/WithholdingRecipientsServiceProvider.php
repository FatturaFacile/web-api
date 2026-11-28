<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\WithholdingRecipients\Domain\Calculation\CalculatorRegistry;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use Modules\WithholdingRecipients\Policies\WithholdingRecipientsPolicy;

/**
 * Mod 356 — Withholding taxes on commissions, royalties, occasional work and amateur sports (Issue #602).
 */
final class WithholdingRecipientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalculatorRegistry::class, static fn (): CalculatorRegistry => CalculatorRegistry::default());
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([RecipientProfile::class, AgentReducedWithholdingCert::class, OccasionalInlNotification::class, RecipientSettlement::class, CuAutonomousRecord::class] as $model) {
            Gate::policy($model, WithholdingRecipientsPolicy::class);
        }
    }
}
