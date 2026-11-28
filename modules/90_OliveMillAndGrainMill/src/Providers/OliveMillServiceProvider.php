<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\OliveMillAndGrainMill\Application\Contracts\SianTransport;
use Modules\OliveMillAndGrainMill\Console\SendHopperRemindersCommand;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Infrastructure\Sian\HttpSianTransport;
use Modules\OliveMillAndGrainMill\Infrastructure\Sian\SandboxSianTransport;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use Modules\OliveMillAndGrainMill\Models\SianMovement;
use Modules\OliveMillAndGrainMill\Models\SianTank;
use Modules\OliveMillAndGrainMill\Policies\OliveMillPolicy;

/**
 * Mod 297 — olive oil mills and grain mills: milling for third parties, yield, molenda settlement, tank map and the SIAN oil register (Issue #543).
 */
final class OliveMillServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SianTransport::class, static fn (): SianTransport => config('olive_mill.sian.driver') === 'http' ? new HttpSianTransport : new SandboxSianTransport);
        $this->app->bind(SianComplianceService::class, static fn (): SianComplianceService => SianComplianceService::fromConfig());
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([OliveDelivery::class, MillingCycle::class, SianTank::class, SianMovement::class, MillingSettlement::class, ByproductMovement::class] as $model) {
            Gate::policy($model, OliveMillPolicy::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SendHopperRemindersCommand::class]);
        }
    }
}
