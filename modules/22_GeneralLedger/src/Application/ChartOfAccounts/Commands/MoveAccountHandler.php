<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountService;

final readonly class MoveAccountHandler
{
    public function __construct(private ChartOfAccountService $service) {}

    public function handle(MoveAccountCommand $command): ChartOfAccount
    {
        return $this->service->move($command->account, $command->parentAccountId);
    }
}
