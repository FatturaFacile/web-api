<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Modules\Associations\Models\LegalParameter;

/**
 * Values that depend on the law and on the year. The value of the company wins over the generic one; when neither
 * exists the caller gets null and must report the check as not configured rather than assume a figure.
 */
final class LegalParameterService
{
    public const string CashStatementThreshold = 'ets.cash_statement_threshold';

    public const string SecondaryRevenueSharePercent = 'ets.secondary_activities_revenue_share_percent';

    public const string SecondaryCostSharePercent = 'ets.secondary_activities_cost_share_percent';

    public const string Lex398Ceiling = 'l398.commercial_income_ceiling';

    public const string Lex398WarningPercent = 'l398.warning_percent';

    public const string Lex398VatFlatDeductionPercent = 'l398.vat_flat_deduction_percent';

    public const string SportContributionThreshold = 'sport.contribution_exemption_threshold';

    public const string SportTaxThreshold = 'sport.tax_exemption_threshold';

    public const string SportWarningPercent = 'sport.warning_percent';

    public const string FiscalRetentionYears = 'retention.fiscal_records_years';

    public const string MembersBookRetentionYears = 'retention.members_book_years_after_leaving';

    public function find(string $code, int $year): ?LegalParameter
    {
        $rows = LegalParameter::query()->where('code', $code)->where('year', $year)->get();

        return $rows->first(static fn (LegalParameter $row): bool => $row->tenant_id !== null) ?? $rows->first();
    }

    public function value(string $code, int $year): ?string
    {
        $value = $this->find($code, $year)?->value;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @return array{code: string, year: int, value: ?string, source_reference: ?string, configured: bool}
     */
    public function describe(string $code, int $year): array
    {
        $row = $this->find($code, $year);

        return ['code' => $code, 'year' => $year, 'value' => $row?->value, 'source_reference' => $row?->source_reference, 'configured' => $row !== null];
    }
}
