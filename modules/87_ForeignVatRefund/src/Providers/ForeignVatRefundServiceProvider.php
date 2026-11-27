<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\ExpenseManagement\Events\ExpenseReportApprovedEvent;
use Modules\ExpenseManagement\Events\ExpenseReportPostedEvent;
use Modules\ForeignVatRefund\Console\SendForeignVatDeadlineAlertsCommand;
use Modules\ForeignVatRefund\Listeners\InterceptForeignVat;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\ForeignVatRefund\Policies\ForeignVatPolicy;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;

/**
 * Mod 227 — Foreign VAT refund management: EU portal (Directive 2008/9/EC), ESTV reciprocity (forms 1222/1223) and Art. 38-ter IT (Modello IVA 79) (Issue #473).
 */
final class ForeignVatRefundServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([CountryVatRefundRule::class, FiscalRepresentativeMandate::class, ForeignVatLedgerEntry::class, VatRefundClaim::class] as $model) {
            Gate::policy($model, ForeignVatPolicy::class);
        }

        Event::listen(PassiveInvoicePostedEvent::class, [InterceptForeignVat::class, 'onPassiveInvoicePosted']);
        Event::listen(ExpenseReportApprovedEvent::class, [InterceptForeignVat::class, 'onExpenseReportApproved']);
        Event::listen(ExpenseReportPostedEvent::class, [InterceptForeignVat::class, 'onExpenseReportPosted']);

        if ($this->app->runningInConsole()) {
            $this->commands([SendForeignVatDeadlineAlertsCommand::class]);
        }
    }
}
