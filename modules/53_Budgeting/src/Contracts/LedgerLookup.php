<?php

declare(strict_types=1);

namespace Modules\Budgeting\Contracts;

use Carbon\CarbonInterface;

/**
 * Where the budgeting module reads the actuals and the dimension master data (accounts, cost centres, projects) from.
 * The default implementation reads the general ledger; a company may bind another source without the budgeting code
 * knowing it.
 */
interface LedgerLookup
{
    /**
     * Posted actuals per account, cost centre, project and calendar month between the two dates (inclusive). The
     * amount follows the normal balance of the account: debit minus credit for debit accounts (expenses), credit minus
     * debit for credit accounts (revenues), so that actuals and budget lines have the same sign.
     *
     * @return list<array{account_id: string, cost_center_id: string|null, project_id: string|null, month: string, amount: string}>
     */
    public function monthlyActuals(string $tenantId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Accounts of the company by id. The kind says whether more than planned is good news (revenue) or bad news
     * (expense and any other kind).
     *
     * @param  list<string>  $ids
     * @return array<string, array{code: string, name: string, kind: 'revenue'|'expense'|'other'}>
     */
    public function accounts(string $tenantId, array $ids): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{code: string, name: string}>
     */
    public function costCenters(string $tenantId, array $ids): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{code: string, name: string}>
     */
    public function projects(string $tenantId, array $ids): array;

    /**
     * Ids of the accounts, cost centres or projects of the company by their code (account code, cost centre code,
     * project number), for the imports. A code that does not exist is missing from the result.
     *
     * @param  'account'|'cost_center'|'project'  $dimension
     * @param  list<string>  $codes
     * @return array<string, string> code => id
     */
    public function idsByCode(string $tenantId, string $dimension, array $codes): array;
}
