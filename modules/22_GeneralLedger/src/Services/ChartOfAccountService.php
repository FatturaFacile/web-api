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
 * Domain service enforcing Chart of Accounts invariants:
 * - An account with postings keeps its type, normal balance, and code (renaming & deactivation remain allowed).
 * - An account cannot become its own ancestor or descendant (cycle prevention).
 * - An account with postings, child sub-accounts, or default posting role cannot be deleted.
 */
final class ChartOfAccountService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerConflictException when the type, normal balance, or code of an account with postings would change
     * @throws ValidationException when the parent would create a cycle
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $data): ChartOfAccount {
            $locked = ChartOfAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $hasPostings = $this->hasPostings($locked);

            $changesClass = (isset($data['account_type']) && $data['account_type'] !== $locked->account_type)
                || (isset($data['normal_balance']) && $data['normal_balance'] !== $locked->normal_balance);

            if ($changesClass && $hasPostings) {
                throw new LedgerConflictException(__('general_ledger_errors.account.type_locked', ['code' => $locked->account_code]));
            }

            if (isset($data['account_code']) && $data['account_code'] !== $locked->account_code && $hasPostings) {
                throw new LedgerConflictException(__('general_ledger_errors.account.code_locked', ['code' => $locked->account_code]));
            }

            if (! empty($data['parent_account_id'])) {
                $this->assertNoCycle($locked, (string) $data['parent_account_id']);
            }

            $parentChanged = array_key_exists('parent_account_id', $data) && $data['parent_account_id'] !== $locked->parent_account_id;
            $codeChanged = isset($data['account_code']) && $data['account_code'] !== $locked->account_code;

            $locked->update($data);

            if ($parentChanged || $codeChanged) {
                $locked->refresh();
                $locked->syncSubtreeHierarchy();
            }

            return $locked->load(['parentAccount', 'vatCode', 'defaultCostCenter']);
        });
    }

    /**
     * Move an account under a new parent (or null for root), preventing cycles and cascading hierarchy paths.
     *
     * @throws ValidationException when the target parent would create a cycle
     */
    public function move(ChartOfAccount $account, ?string $parentAccountId): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $parentAccountId): ChartOfAccount {
            $locked = ChartOfAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($parentAccountId !== null && $parentAccountId !== '') {
                $this->assertNoCycle($locked, $parentAccountId);
            }

            $locked->update(['parent_account_id' => $parentAccountId ?: null]);
            $locked->refresh();
            $locked->syncSubtreeHierarchy();

            return $locked->load(['parentAccount', 'vatCode', 'defaultCostCenter']);
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

    public function hasPostings(ChartOfAccount $account): bool
    {
        if (JournalLine::withoutGlobalScopes()->where('account_id', $account->id)->exists()) {
            return true;
        }

        return DB::table('general_ledger_lines')
            ->where('account_id', $account->id)
            ->exists();
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
