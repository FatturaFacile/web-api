<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\VatCode;
use Modules\GeneralLedger\Models\VatTaxSetting;

/**
 * Resolves the ledger accounts of the VAT settlement: the ones configured for the tenant and on its VAT codes. When
 * none is configured it falls back to the codes the posting listeners write to.
 */
final class VatAccountResolver
{
    private const string DEFAULT_OUTPUT_CODE = '2200';

    /** Listeners post purchase VAT to 1400; 1170 is the input tax account of the Swiss chart used by the desktop. */
    private const array DEFAULT_INPUT_CODES = ['IT' => ['1400'], 'CH' => ['1400', '1170']];

    private const string DEFAULT_SETTLEMENT_CODE = '2210';

    private const string DEFAULT_INTEREST_CODE = '7500';

    public function accountsFor(string $tenantId, string $country): VatAccountSet
    {
        $settings = $this->settingsOf($tenantId);
        $vatCodes = VatCode::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->get();

        $outputIds = array_merge(
            $settings->output_vat_account_ids ?? [],
            $vatCodes->pluck('gl_sales_tax_account_id')->filter()->all(),
        );
        $inputIds = array_merge(
            $settings->input_vat_account_ids ?? [],
            $vatCodes->pluck('gl_purchase_tax_account_id')->filter()->all(),
        );

        if ($outputIds === []) {
            $outputIds = $this->accountIdsOf($tenantId, [self::DEFAULT_OUTPUT_CODE]);
        }

        if ($inputIds === []) {
            $inputIds = $this->accountIdsOf($tenantId, self::DEFAULT_INPUT_CODES[$country] ?? self::DEFAULT_INPUT_CODES['IT']);
        }

        return new VatAccountSet(
            array_values(array_unique(array_map(strval(...), $outputIds))),
            array_values(array_unique(array_map(strval(...), $inputIds))),
        );
    }

    /**
     * The account holding the debt or credit towards the tax authority, created on first use.
     */
    public function settlementAccountId(string $tenantId): string
    {
        return $this->settingsOf($tenantId)->settlement_account_id
            ?? $this->findOrCreate($tenantId, self::DEFAULT_SETTLEMENT_CODE, 'Erario c/IVA (VAT Settlement)', 'liability', 'credit');
    }

    /**
     * The expense account of the quarterly interest, created on first use.
     */
    public function interestExpenseAccountId(string $tenantId): string
    {
        return $this->settingsOf($tenantId)->interest_expense_account_id
            ?? $this->findOrCreate($tenantId, self::DEFAULT_INTEREST_CODE, 'Interessi su IVA trimestrale (Quarterly VAT Interest)', 'expense', 'debit');
    }

    public function settingsOf(string $tenantId): VatTaxSetting
    {
        return VatTaxSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->first()
            ?? new VatTaxSetting(['tenant_id' => $tenantId]);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function accountIdsOf(string $tenantId, array $codes): array
    {
        return ChartOfAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('account_code', $codes)
            ->pluck('id')
            ->map(strval(...))
            ->all();
    }

    private function findOrCreate(string $tenantId, string $code, string $name, string $type, string $normalBalance): string
    {
        $account = ChartOfAccount::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );

        return (string) $account->id;
    }
}
