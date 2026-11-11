<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\LedgerBalanceReader;

/**
 * The trial balance of one entity as the consolidation takes it, read from the entity's own statutory ledger with the
 * conventions of LedgerBalanceReader (posted and reversed entries, opening entries left out, accounting currency of the
 * entity): the balance sheet accounts cumulated to the period end, with the closing entries of earlier years, and the
 * income accounts from the start of the fiscal year to the period end (year to date). The year-end closing entry of the
 * year itself is left out, so the result of the year stays on the income accounts.
 *
 * The tenant is always explicit: nothing here depends on the active tenant context, and nothing is written.
 */
final readonly class EntityBalanceReader
{
    public function __construct(private LedgerBalanceReader $balances) {}

    /**
     * @return list<array{account_id: string, account_code: string, account_name: string, account_type: string, normal_balance: string, net: string}> Debit minus credit in the accounting currency of the entity, zero balances left out, ordered by account code
     */
    public function read(string $tenantId, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): array
    {
        $rows = [];

        foreach ($this->balances->cumulative($tenantId, $yearStart, $periodEnd) as $row) {
            $rows[(string) $row->account_id] = $this->shape($row);
        }

        foreach ($this->balances->movements($tenantId, $yearStart, $periodEnd) as $row) {
            if (in_array($row->account_type, LedgerBalanceReader::IncomeTypes, true)) {
                $rows[(string) $row->account_id] = $this->shape($row);
            }
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => ! ConsolidationMath::isZero($row['net'])));
        usort($rows, static fn (array $left, array $right): int => [$left['account_code'], $left['account_id']] <=> [$right['account_code'], $right['account_id']]);

        return $rows;
    }

    /**
     * @return array{account_id: string, account_code: string, account_name: string, account_type: string, normal_balance: string, net: string}
     */
    private function shape(object $row): array
    {
        return [
            'account_id' => (string) $row->account_id,
            'account_code' => (string) $row->account_code,
            'account_name' => (string) $row->account_name,
            'account_type' => (string) $row->account_type,
            'normal_balance' => (string) $row->normal_balance,
            'net' => ConsolidationMath::sub((string) $row->debit, (string) $row->credit),
        ];
    }
}
