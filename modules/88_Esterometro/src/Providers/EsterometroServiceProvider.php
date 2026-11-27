<?php

declare(strict_types=1);

namespace Modules\Esterometro\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Esterometro\Console\Commands\CheckEsterometroDeadlinesCommand;
use Modules\Esterometro\Listeners\PassiveInvoiceSelfInvoiceListener;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Policies\SelfInvoicePolicy;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;

final class EsterometroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::policy(SelfInvoice::class, SelfInvoicePolicy::class);

        Event::listen(PassiveInvoicePostedEvent::class, PassiveInvoiceSelfInvoiceListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckEsterometroDeadlinesCommand::class,
            ]);
        }
    }
}
