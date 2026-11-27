<?php

declare(strict_types=1);

namespace Modules\DownPayments\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\DownPayments\Listeners\ConvertProformaOnCollectionListener;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;
use Modules\DownPayments\Policies\DownPaymentPolicy;
use Modules\Treasury\Events\CollectionReceivedEvent;

/**
 * Mod 203 — Down-payment chain: advance invoices (TD02), pro-forma requests, earnest money and security deposits, multi-rate
 * deduction in the final settlement invoice and advance credit notes (Issue #449).
 */
final class DownPaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([DownPaymentChain::class, DownPaymentInstallment::class, DownPaymentLink::class, EarnestAndSecurityDeposit::class] as $model) {
            Gate::policy($model, DownPaymentPolicy::class);
        }

        Event::listen(CollectionReceivedEvent::class, [ConvertProformaOnCollectionListener::class, 'handle']);
    }
}
