<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\JournalLine;

/**
 * Changes to the chart of accounts that keep the posted ledger consistent: an account with postings keeps its type and
 * normal balance (the statements already classified its amounts) and is deactivated instead of deleted; an account
 * cannot become its own ancestor; an account used as a default posting account cannot be deleted.
 */
final class ChartOfAccountService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerConflictException when the type or normal balance of an account with postings would change
     * @throws ValidationException when the parent would create a cycle
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $data): ChartOfAccount {
            $locked = ChartOfAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $changesClass = (isset($data['account_type']) && $data['account_type'] !== $locked->account_type)
                || (isset($data['normal_balance']) && $data['normal_balance'] !== $locked->normal_balance);

            if ($changesClass && $this->hasPostings($locked)) {
                throw new LedgerConflictException(__('general_ledger_errors.account.type_locked', ['code' => $locked->account_code]));
            }

            if (! empty($data['parent_account_id'])) {
                $this->assertNoCycle($locked, (string) $data['parent_account_id']);
            }

            $locked->update($data);

            return $locked;
        });
    }

    /**
     * @throws LedgerConflictException when the account has postings, sub-accounts or is a default posting account
     */
    public function delete(ChartOfAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $locked = ChartOfAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($this->hasPostings($locked)) {
                throw new LedgerConflictException(__('general_ledger_errors.account.has_postings', ['code' => $locked->account_code]));
            }

            if (ChartOfAccount::where('parent_account_id', $locked->id)->exists()) {
                throw new LedgerConflictException(__('general_ledger_errors.account.has_sub_accounts', ['code' => $locked->account_code]));
            }

            if (DefaultPostingAccount::where('account_id', $locked->id)->exists()) {
                throw new LedgerConflictException(__('general_ledger_errors.account.is_default_posting', ['code' => $locked->account_code]));
            }

            $locked->delete();
        });
    }

    private function hasPostings(ChartOfAccount $account): bool
    {
        return JournalLine::withoutGlobalScopes()->where('account_id', $account->id)->exists();
    }

    /**
     * @throws ValidationException
     */
    private function assertNoCycle(ChartOfAccount $account, string $parentId): void
    {
        $seen = [];
        $current = $parentId;

        while ($current !== '' && ! isset($seen[$current])) {
            if ($current === (string) $account->id) {
                throw ValidationException::withMessages(['parent_account_id' => [__('general_ledger_errors.account.parent_cycle')]]);
            }

            $seen[$current] = true;
            $current = (string) (ChartOfAccount::whereKey($current)->value('parent_account_id') ?? '');
        }
    }
}
