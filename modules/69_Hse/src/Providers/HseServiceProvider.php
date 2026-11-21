<?php

declare(strict_types=1);

namespace Modules\Hse\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Hse\Console\CheckHseExpirationsCommand;
use Modules\Hse\Domain\Contracts\EmployeeDossierGatewayInterface;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Contracts\HseNotificationGatewayInterface;
use Modules\Hse\Domain\Contracts\Mod074AccidentClaimBridgeInterface;
use Modules\Hse\Domain\Contracts\PpeStockGatewayInterface;
use Modules\Hse\Infrastructure\Services\EmployeeDossierGateway;
use Modules\Hse\Infrastructure\Services\HseClearanceGatekeeper;
use Modules\Hse\Infrastructure\Services\Mod051PpeStockGateway;
use Modules\Hse\Infrastructure\Services\Mod074AccidentClaimBridge;
use Modules\Hse\Infrastructure\Services\NotificationEngineGateway;

/**
 * Workplace health, safety and training (Mod 099): the hard-block gatekeeper that Mod 064, 084 and 108 resolve from the
 * container, the adapters towards the warehouse (051), the employee dossier (068), the notification engine (008) and
 * the statutory claims (074), the routes and the daily expiration command.
 */
final class HseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HseClearanceGatekeeperInterface::class, HseClearanceGatekeeper::class);
        $this->app->bind(PpeStockGatewayInterface::class, Mod051PpeStockGateway::class);
        $this->app->bind(EmployeeDossierGatewayInterface::class, EmployeeDossierGateway::class);
        $this->app->bind(HseNotificationGatewayInterface::class, NotificationEngineGateway::class);
        $this->app->bind(Mod074AccidentClaimBridgeInterface::class, Mod074AccidentClaimBridge::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckHseExpirationsCommand::class]);
        }
    }
}
