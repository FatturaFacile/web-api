<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountService;

final readonly class UpdateAccountHandler
{
    public function __construct(private ChartOfAccountService $service) {}

    public function handle(UpdateAccountCommand $command): ChartOfAccount
    {
        return $this->service->update($command->account, $command->attributes);
    }
}
