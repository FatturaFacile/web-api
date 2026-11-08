<?php

declare(strict_types=1);

namespace Modules\Contracts\Providers;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Events\ContractObligationDueEvent;
use Modules\Contracts\Listeners\DispatchOutboxOnContractEventListener;

/**
 * Registers the routes, gates and event listeners of the Contracts module.
 */
final class ContractsServiceProvider extends ServiceProvider
{
    /**
     * Roles that can terminate a contract regardless of its kind.
     *
     * @var list<string>
     */
    private const array TerminationRoles = ['owner', 'admin', 'accountant'];

    /**
     * Bootstrap the module.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        // Terminating is final: owners, administrators and accountants, plus the manager of that kind of contract.
        Gate::define('contracts.terminate', static function (User $user, ContractKind $kind): bool {
            $role = (string) $user->getRoleInTenant((string) app(TenantContext::class)->getTenantId());

            return in_array($role, self::TerminationRoles, true)
                || ($kind === ContractKind::Supplier && $role === 'procurement_manager')
                || ($kind === ContractKind::Customer && in_array($role, ['sales_manager', 'service_manager'], true));
        });

        Event::listen(ContractObligationDueEvent::class, DispatchOutboxOnContractEventListener::class);
    }
}
