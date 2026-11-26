<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashRegister;

/**
 * Tells which currencies of a company still carry open items or balances (open receivables and payables, bank accounts and cash
 * registers with a balance): such a currency cannot be deactivated.
 */
final class CurrencyUsageChecker
{
    /**
     * @param  list<string>  $currencies
     * @return list<string> the currencies among them that are still in use
     */
    public function inUse(string $tenantId, array $currencies): array
    {
        $inUse = [];

        foreach (array_map('strtoupper', $currencies) as $currency) {
            if ($this->hasOpenItems($tenantId, $currency) || $this->hasBalance($tenantId, $currency)) {
                $inUse[] = $currency;
            }
        }

        return $inUse;
    }

    private function hasOpenItems(string $tenantId, string $currency): bool
    {
        foreach ([CustomerLedger::class, SupplierLedger::class] as $ledger) {
            if ($ledger::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('currency', $currency)->whereIn('status', ['open', 'partially_paid'])->where('open_amount', '!=', 0)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function hasBalance(string $tenantId, string $currency): bool
    {
        foreach ([BankAccount::class, CashRegister::class] as $model) {
            if ($model::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->where('currency', $currency)->where('current_balance', '!=', 0)->exists()) {
                return true;
            }
        }

        return false;
    }
}
