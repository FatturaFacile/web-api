<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Services\ChartOfAccountService;

final readonly class DeleteAccountHandler
{
    public function __construct(private ChartOfAccountService $service) {}

    public function handle(DeleteAccountCommand $command): void
    {
        $this->service->delete($command->account);
    }
}
