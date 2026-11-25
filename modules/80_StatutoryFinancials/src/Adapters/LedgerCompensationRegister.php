<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Adapters;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\StatutoryFinancials\Contracts\CompensationRegisterPort;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Compensation of directors and statutory auditors: the movements of the year on the ledger accounts configured by code prefix.
 * Advances and guarantees are not in the ledger by nature and stay to be entered in the notes.
 */
final class LedgerCompensationRegister implements CompensationRegisterPort
{
    public function __construct(private readonly LedgerBalanceReader $balances) {}

    public function compensation(string $tenantId, string $family, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $movements = $this->balances->movements($tenantId, $from, $to);
        $rows = [];

        foreach (['directors', 'auditors'] as $role) {
            $prefixes = (array) config('statutory_financials.compensation_accounts.'.$role.'.'.$family, []);
            $fees = '0';

            foreach ($movements as $account) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with((string) $account->account_code, (string) $prefix)) {
                        $fees = Money::add($fees, Money::sub((string) $account->debit, (string) $account->credit));
                    }
                }
            }

            $rows[] = ['role' => $role, 'fees' => $fees, 'advances' => '0', 'guarantees' => '0'];
        }

        return ['available' => true, 'source' => 'General ledger', 'rows' => $rows];
    }
}
