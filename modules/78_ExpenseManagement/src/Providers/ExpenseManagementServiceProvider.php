<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\ExpenseManagement\Contracts\ProjectRebillingInterface;
use Modules\ExpenseManagement\Contracts\ReceiptDocumentStoreInterface;
use Modules\ExpenseManagement\Contracts\ReceiptOcrProviderInterface;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Policies\ExpenseReportPolicy;
use Modules\ExpenseManagement\Services\DeterministicReceiptParser;
use Modules\ExpenseManagement\Services\DmsReceiptDocumentStore;
use Modules\ExpenseManagement\Services\LocalReceiptDocumentStore;
use Modules\ExpenseManagement\Services\ProjectsRebillingAdapter;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;

/**
 * Mod 075 — Expense and travel management (Issue #166).
 */
final class ExpenseManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FiscalStrategyRegistry::class);

        // The deterministic parser works offline; an OCR engine rebinds the interface.
        $this->app->bind(ReceiptOcrProviderInterface::class, DeterministicReceiptParser::class);
        $this->app->bind(ProjectRebillingInterface::class, ProjectsRebillingAdapter::class);
        $this->app->bind(
            ReceiptDocumentStoreInterface::class,
            fn ($app): ReceiptDocumentStoreInterface => config('expense_management.receipts.store') === 'dms'
                ? $app->make(DmsReceiptDocumentStore::class)
                : $app->make(LocalReceiptDocumentStore::class),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        Gate::policy(ExpenseReport::class, ExpenseReportPolicy::class);
    }
}
