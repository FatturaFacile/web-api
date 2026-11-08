<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\DefaultPostingAccount;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Reads and changes the ledger settings of a tenant: the default posting accounts per role and the four-eyes policy
 * of manual journals.
 */
final readonly class LedgerSettingsService
{
    public function __construct(private PostingAccountResolver $resolver) {}

    /**
     * The settings as the API shows them.
     *
     * @return array{posting_accounts: array<string, array{account_id: ?string, account_code: ?string, account_name: ?string, source: ?string}>, manual_journal_approval_required: bool, manual_journal_approval_threshold: ?string}
     */
    public function show(string $tenantId): array
    {
        $setting = $this->setting($tenantId);
        $accounts = [];

        foreach ($this->resolver->all($tenantId) as $role => $resolved) {
            $accounts[$role] = [
                'account_id' => $resolved['account']?->id !== null ? (string) $resolved['account']->id : null,
                'account_code' => $resolved['account']?->account_code,
                'account_name' => $resolved['account']?->name,
                'source' => $resolved['source'],
            ];
        }

        return [
            'posting_accounts' => $accounts,
            'manual_journal_approval_required' => $setting !== null && $setting->manual_journal_approval_required,
            'manual_journal_approval_threshold' => $setting?->manual_journal_approval_threshold !== null ? (string) $setting->manual_journal_approval_threshold : null,
        ];
    }

    /**
     * Changes the settings given; a role set to null goes back to the preference code, if any.
     *
     * @param  array{posting_accounts?: array<string, ?string>, manual_journal_approval_required?: bool, manual_journal_approval_threshold?: string|int|float|null}  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException when an account does not fit its role
     */
    public function update(string $tenantId, array $data): array
    {
        DB::transaction(function () use ($tenantId, $data): void {
            foreach ($data['posting_accounts'] ?? [] as $roleValue => $accountId) {
                $role = PostingAccountRole::from((string) $roleValue);
                $existing = DefaultPostingAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('role', $role->value)->lockForUpdate()->first();

                if ($accountId === null) {
                    $existing?->forceDelete();

                    continue;
                }

                $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->first();

                if ($account === null || ! in_array($account->account_type, $role->allowedAccountTypes(), true)) {
                    throw ValidationException::withMessages(["posting_accounts.{$role->value}" => ["The account for [{$role->value}] must be an account of type ".implode(' or ', $role->allowedAccountTypes()).' of this tenant.']]);
                }

                if ($existing !== null) {
                    $existing->update(['account_id' => $account->id]);
                } else {
                    DefaultPostingAccount::create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'role' => $role->value, 'account_id' => $account->id]);
                }
            }

            if (array_key_exists('manual_journal_approval_required', $data) || array_key_exists('manual_journal_approval_threshold', $data)) {
                $setting = LedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->first()
                    ?? new LedgerSetting(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId]);

                if (array_key_exists('manual_journal_approval_required', $data)) {
                    $setting->manual_journal_approval_required = (bool) $data['manual_journal_approval_required'];
                }

                if (array_key_exists('manual_journal_approval_threshold', $data)) {
                    $threshold = $data['manual_journal_approval_threshold'];
                    $setting->setAttribute('manual_journal_approval_threshold', $threshold === null ? null : Decimal::normalize((string) $threshold));
                }

                $setting->save();
            }
        });

        return $this->show($tenantId);
    }

    /**
     * Whether a manual journal of that total needs a second person to approve it before posting.
     */
    public function approvalRequiredFor(string $tenantId, string $totalAmount): bool
    {
        $setting = $this->setting($tenantId);

        if ($setting === null || ! $setting->manual_journal_approval_required) {
            return false;
        }

        $threshold = $setting->manual_journal_approval_threshold;

        return $threshold === null || Decimal::compare($totalAmount, (string) $threshold) >= 0;
    }

    private function setting(string $tenantId): ?LedgerSetting
    {
        return LedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->first();
    }
}
