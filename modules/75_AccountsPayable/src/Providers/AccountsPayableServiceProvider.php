<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Accounts payable (Mod 015): the supplier ledger with disputes and blocks, the cash-discount (Skonto) engine, the payment
 * runs with the CFO approval, and the payables aging with its exports. Loads the routes.
 */
final class AccountsPayableServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
