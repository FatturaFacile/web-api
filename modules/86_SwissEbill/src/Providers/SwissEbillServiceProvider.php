<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Providers;

use App\Events\Sales\CustomerPaymentRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\SwissEbill\Console\PollPendingEbillStatusesCommand;
use Modules\SwissEbill\Domain\Events\EbillRejected;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\EbillInvoiceReferenceResolver;
use Modules\SwissEbill\Infrastructure\Repositories\EloquentEbillSettingRepository;
use Modules\SwissEbill\Infrastructure\Repositories\EloquentEbillTransmissionRepository;
use Modules\SwissEbill\Listeners\AutoTransmitOnInvoicePosted;
use Modules\SwissEbill\Listeners\NotifyAccountingOnEbillRejected;
use Modules\SwissEbill\Listeners\ReconcileEbillOnPaymentRecorded;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillTransmission;
use Modules\SwissEbill\Policies\EbillPolicy;

/**
 * Mod 025 — Swiss eBill (SIX Paynet / PostFinance) direct transmission, participant directory, lifecycle and camt.054 settlement (Issue #283).
 */
final class SwissEbillServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EbillSettingRepositoryInterface::class, EloquentEbillSettingRepository::class);
        $this->app->bind(EbillTransmissionRepositoryInterface::class, EloquentEbillTransmissionRepository::class);

        $this->app->tag([EbillInvoiceReferenceResolver::class], 'bank.invoice_reference_resolvers');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(EbillSetting::class, EbillPolicy::class);
        Gate::policy(EbillTransmission::class, EbillPolicy::class);

        Event::listen(CustomerPaymentRecorded::class, ReconcileEbillOnPaymentRecorded::class);
        Event::listen(EbillRejected::class, NotifyAccountingOnEbillRejected::class);

        if (class_exists(InvoicePostedEvent::class)) {
            Event::listen(InvoicePostedEvent::class, AutoTransmitOnInvoicePosted::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PollPendingEbillStatusesCommand::class]);
        }
    }
}
