<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class CreateAccountHandler
{
    public function handle(CreateAccountCommand $command): ChartOfAccount
    {
        $account = ChartOfAccount::create(array_merge($command->attributes, [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $command->tenantId,
        ]));

        return $account->load(['parentAccount', 'vatCode', 'defaultCostCenter']);
    }
}
