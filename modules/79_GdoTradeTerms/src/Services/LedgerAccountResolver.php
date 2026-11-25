<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoContract;

/**
 * The ledger accounts of the accrual and settlement entries: the ones chosen on the contract, otherwise the chart codes of
 * the configuration (gdo_trade_terms.accounts). A missing account refuses the posting; it never falls back to another one.
 */
final class LedgerAccountResolver
{
    /** @var list<string> */
    public const array Roles = ['expense', 'provision', 'payable', 'vat'];

    public function resolve(GdoContract $contract, string $role): string
    {
        $chosen = $contract->getAttribute($role.'_account_id');

        if (is_string($chosen) && $chosen !== '') {
            return $chosen;
        }

        $code = (string) config('gdo_trade_terms.accounts.'.$role, '');
        $id = $code === '' ? null : DB::table('chart_of_accounts')
            ->where('tenant_id', $contract->tenant_id)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('account_code', $code)->orWhere('code', $code))
            ->value('id');

        if ($id === null) {
            throw new GdoTradeTermsException('ledger_account_missing', 422, ['role' => $role, 'code' => $code]);
        }

        return (string) $id;
    }
}
