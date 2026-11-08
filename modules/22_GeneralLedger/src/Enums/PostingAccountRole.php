<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

/**
 * The function an account performs in the automatic postings of a tenant. Each tenant chooses its own account for
 * each role in its chart of accounts: the application ships no account code. Documents and closings read the account
 * through the PostingAccountResolver.
 */
enum PostingAccountRole: string
{
    case SalesRevenue = 'sales_revenue';
    case PurchaseExpense = 'purchase_expense';
    case OutputVat = 'output_vat';
    case InputVat = 'input_vat';
    case Receivables = 'receivables';
    case Payables = 'payables';
    case RetainedEarnings = 'retained_earnings';
    case FxUnrealisedGain = 'fx_unrealised_gain';
    case FxUnrealisedLoss = 'fx_unrealised_loss';
    case Rounding = 'rounding';

    /**
     * Account types of the chart of accounts that can fill the role. A revenue role on an asset account would put the
     * postings in the wrong statement, so it is refused when the setting is saved.
     *
     * @return list<string>
     */
    public function allowedAccountTypes(): array
    {
        return match ($this) {
            self::SalesRevenue => ['revenue'],
            self::PurchaseExpense => ['expense', 'cogs'],
            self::OutputVat, self::Payables => ['liability'],
            self::InputVat, self::Receivables => ['asset'],
            self::RetainedEarnings => ['equity'],
            self::FxUnrealisedGain => ['revenue'],
            self::FxUnrealisedLoss => ['expense'],
            self::Rounding => ['revenue', 'expense'],
        };
    }

    /**
     * Where the tenant may already have set the account code before this setting existed, as a path of
     * `tenants.settings.preferences`: the accounting preferences of the settings page, and the Treasury ledger defaults
     * for the exchange differences (the same accounts the bank revaluation uses). Null when there is no such place.
     *
     * @return list<string>|null
     */
    public function preferencePath(): ?array
    {
        return match ($this) {
            self::SalesRevenue => ['accounting', 'accounts', 'revenue_account_code'],
            self::PurchaseExpense => ['accounting', 'accounts', 'cost_account_code'],
            self::OutputVat => ['accounting', 'accounts', 'output_vat_account_code'],
            self::InputVat => ['accounting', 'accounts', 'input_vat_account_code'],
            self::Receivables => ['accounting', 'accounts', 'receivables_account_code'],
            self::Payables => ['accounting', 'accounts', 'payables_account_code'],
            self::Rounding => ['accounting', 'accounts', 'rounding_account_code'],
            self::FxUnrealisedGain => ['banking', 'ledger_defaults', 'fx_unrealised_gain_account_code'],
            self::FxUnrealisedLoss => ['banking', 'ledger_defaults', 'fx_unrealised_loss_account_code'],
            self::RetainedEarnings => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
