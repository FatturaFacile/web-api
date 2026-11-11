<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

/**
 * The working state of one computation of the consolidation, before anything is stored: the members with their
 * translated balances, the adjustments generated so far and the warnings. Amounts are decimal strings in group currency
 * unless the key says local; `group_net` is debit minus credit.
 *
 * @phpstan-type MemberInfo array{tenant_id: string, name: string, currency: string, ownership_percentage: string, consolidation_method: string, is_parent: bool, holder_tenant_id: ?string, effective_ownership: string, consolidation_share: string, fiscal_year_offset_months: int, year_start: string}
 * @phpstan-type BalanceRow array{member_tenant_id: string, consolidation_method: string, ownership_percentage: string, consolidated_share: string, local_currency: string, account_id: ?string, account_code: string, account_name: string, account_type: string, normal_balance: string, local_net: string, rate_basis: string, rate: string, group_net: string, is_residual: bool}
 * @phpstan-type AdjustmentLine array{entity_tenant_id: ?string, account_code: string, account_name: string, account_type: string, debit: string, credit: string}
 * @phpstan-type Adjustment array{type: string, relation_id: ?string, member_tenant_id: ?string, description: string, amount: string, lines: list<AdjustmentLine>}
 */
final class ConsolidationDraft
{
    /**
     * @var array<string, MemberInfo>
     */
    public array $members = [];

    /**
     * @var list<array{tenant_id: string, currency: string, closing: string, average: string, historical: string}>
     */
    public array $rates = [];

    /**
     * @var list<BalanceRow>
     */
    public array $balances = [];

    /**
     * Balance rows by member and account id, for the mappings.
     *
     * @var array<string, array<string, BalanceRow>>
     */
    public array $index = [];

    /**
     * @var list<Adjustment>
     */
    public array $adjustments = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $warnings = [];

    /**
     * @var list<array{member_tenant_id: string, ownership_percentage: string, net_assets: string, profit: string, nci_net_assets: string, nci_profit: string}>
     */
    public array $nonControllingInterests = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $mappings = [];

    /**
     * Inputs of the extensions that differ from the defaults (indirect holdings, offsets, acquisitions, transactions,
     * dividends, account mappings, group settings), kept in the run and in its fingerprint.
     *
     * @var array<string, mixed>
     */
    public array $extensions = [];

    /**
     * Acquisition records by investee, with their fair-value adjustments and impairments.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $acquisitions = [];

    /**
     * Investment eliminated against the equity of a subsidiary, by investor: it leaves the net assets that the
     * minority interests of the investor are measured on.
     *
     * @var array<string, string>
     */
    public array $investmentsEliminated = [];

    /**
     * Fair-value adjustments net of deferred tax (100 %) by investee, which belong to its net assets.
     *
     * @var array<string, string>
     */
    public array $fairValueNet = [];

    /**
     * @var array<string, array<string, array{code: string, name: string, type: string}>>
     */
    public array $accountRefs = [];

    /**
     * Account mappings of the members to the group chart: member, source account id, group code and name.
     *
     * @var array<string, array<string, array{code: string, name: ?string}>>
     */
    public array $accountMaps = [];

    public string $yearStart = '';

    public string $periodEnd = '';

    /**
     * @param  array<string, mixed>  $warning
     */
    public function warn(string $code, string $message, array $warning = []): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message, ...$warning];
    }

    public function isFull(string $tenantId): bool
    {
        return ($this->members[$tenantId]['consolidation_method'] ?? null) === 'full';
    }

    /**
     * Full or proportional: the entity is added line by line.
     */
    public function isConsolidated(string $tenantId): bool
    {
        return in_array($this->members[$tenantId]['consolidation_method'] ?? null, ['full', 'proportional'], true);
    }

    /**
     * The share of an entity that enters the consolidated accounts as a fraction (1 for a full member).
     */
    public function shareFraction(string $tenantId): string
    {
        return bcdiv($this->members[$tenantId]['consolidation_share'] ?? '100', '100', 10);
    }

    /**
     * Balance of an account in its natural sign (positive on its normal side) in group currency, zero when the member
     * has no balance on it.
     */
    public function natural(string $tenantId, ?string $accountId): string
    {
        $row = $accountId === null ? null : ($this->index[$tenantId][$accountId] ?? null);

        if ($row === null) {
            return ConsolidationMath::zero();
        }

        return $row['normal_balance'] === 'debit' ? $row['group_net'] : ConsolidationMath::neg($row['group_net']);
    }

    /**
     * @return BalanceRow|null
     */
    public function row(string $tenantId, ?string $accountId): ?array
    {
        return $accountId === null ? null : ($this->index[$tenantId][$accountId] ?? null);
    }

    public function memberName(string $tenantId): string
    {
        return $this->members[$tenantId]['name'] ?? $tenantId;
    }
}
