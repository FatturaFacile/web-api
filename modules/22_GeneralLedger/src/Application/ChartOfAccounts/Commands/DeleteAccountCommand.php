<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class DeleteAccountCommand
{
    public function __construct(public ChartOfAccount $account) {}
}
