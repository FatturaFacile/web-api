<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Providers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AgentCommissions\Console\SyncAgentCommissionsCommand;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\AgentYearBalance;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Models\FiscalParameter;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Models\Zone;
use Modules\AgentCommissions\Observers\CommissionSyncObserver;
use Modules\AgentCommissions\Policies\AgentCommissionPolicy;
use Modules\AgentCommissions\Services\FiscalParameterResolver;
use Modules\AgentCommissions\Services\RuleResolver;
use Modules\AgentCommissions\Services\ZoneResolver;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Models\BankStatementLine;

/**
 * Mod 041 — Sales commissions and agent management (Issue #338).
 */
final class AgentCommissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolvers cache what they read; a scoped binding gives every request and every queued job a fresh cache.
        $this->app->scoped(FiscalParameterResolver::class);
        $this->app->scoped(RuleResolver::class);
        $this->app->scoped(ZoneResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([AgentProfile::class, AgentYearBalance::class, CommissionEntry::class, CommissionRule::class, FiscalParameter::class, Statement::class, Zone::class] as $model) {
            Gate::policy($model, AgentCommissionPolicy::class);
        }

        Invoice::observe(CommissionSyncObserver::class);
        SalesInvoice::observe(CommissionSyncObserver::class);

        $payments = static fn (): CommissionSyncObserver => app(CommissionSyncObserver::class);
        Payment::saved(static fn (Payment $payment) => $payments()->paymentSaved($payment));
        Payment::deleted(static fn (Payment $payment) => $payments()->paymentDeleted($payment));
        BankStatementLine::updated(static fn (BankStatementLine $line) => $payments()->lineUpdated($line));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAgentCommissionsCommand::class]);
        }
    }
}
