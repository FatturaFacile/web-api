<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationAccountMapping;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;

/**
 * Reconciliation of the intercompany balances of a group for a fiscal period of the parent entity: for every mapped pair
 * of accounts (receivable against payable, revenue against cost) the balance of each entity, in its own currency and in
 * group currency, and the difference. The same reading and translation as a run (ConsolidationTranslator): assets and
 * liabilities at the period end at the closing rate, income accounts year to date at the average rate. A pair differs
 * when the absolute difference in group currency is above the tolerance of its relation.
 *
 * Both amounts are in the natural sign of their account (a receivable and a payable are both positive when they
 * stand on their normal side), so the difference is simply A minus B; a negative balance on an account shows that it
 * stands on the wrong side. Investment and equity mappings are not intercompany balances and are not part of this
 * report. Like a run it reads each entity from its own fiscal year start (the offset of the member, with the same
 * alignment rules) and shows the account codes of the group mapping. The proportional scaling of a run is not applied:
 * the report compares the balances of the entities as they stand. Nothing is stored and nothing is written; reading it
 * needs the access to every member entity.
 */
final readonly class IntercompanyReconciliationService
{
    public function __construct(
        private ConsolidationAccessGuard $guard,
        private ConsolidationTranslator $translator,
        private ConsolidationCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ConsolidationException when a foreign entity has no exchange rate
     */
    public function report(User $user, ConsolidationGroup $group, FiscalPeriod $period): array
    {
        $this->guard->assertCanReadGroup($user, $group);

        if ((string) $period->tenant_id !== (string) $group->tenant_id) {
            throw new ConsolidationException('The fiscal period must belong to the parent entity of the group.');
        }

        $year = FiscalYear::withoutGlobalScopes()->where('tenant_id', $period->tenant_id)->find($period->fiscal_year_id);

        if ($year === null) {
            throw new ConsolidationException('The fiscal period has no fiscal year.');
        }

        $yearStart = CarbonImmutable::parse($year->start_date);
        $periodEnd = CarbonImmutable::parse($period->end_date);
        $groupCurrency = strtoupper($group->group_currency);
        $translated = [];
        $rows = [];
        $maps = [];

        foreach (ConsolidationAccountMapping::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->get() as $map) {
            $maps[(string) $map->member_tenant_id][(string) $map->account_id] = ['code' => (string) $map->group_account_code, 'name' => $map->group_account_name];
        }

        $relations = IntercompanyRelation::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('id')->get();
        $names = Tenant::withoutGlobalScopes()->whereIn('id', $relations->pluck('entity_a_tenant_id')->merge($relations->pluck('entity_b_tenant_id'))->unique()->all())->pluck('name', 'id');

        foreach ($relations as $relation) {
            $mappings = IntercompanyAccountMapping::withoutGlobalScopes()
                ->where('relation_id', $relation->id)
                ->where('tenant_id', $group->tenant_id)
                ->whereIn('mapping_type', [IntercompanyMappingType::ReceivablePayable->value, IntercompanyMappingType::RevenueCost->value])
                ->orderBy('id')
                ->get();

            foreach ($mappings as $mapping) {
                foreach ([(string) $relation->entity_a_tenant_id, (string) $relation->entity_b_tenant_id] as $tenantId) {
                    if (! isset($translated[$tenantId])) {
                        $memberStart = $this->calculator->memberYearStart($group, $tenantId, $yearStart, $periodEnd);
                        $translated[$tenantId] = $this->index($this->translator->translate($tenantId, $groupCurrency, $memberStart, $periodEnd), $memberStart);
                    }
                }

                $sideA = $this->side((string) $relation->entity_a_tenant_id, (string) $mapping->account_a_id, $translated, $names, $maps);
                $sideB = $this->side((string) $relation->entity_b_tenant_id, (string) $mapping->account_b_id, $translated, $names, $maps);
                $difference = ConsolidationMath::sub($sideA['group_amount'], $sideB['group_amount']);
                $hasDifference = ConsolidationMath::cmp(ConsolidationMath::abs($difference), (string) $relation->tolerance_amount) > 0;

                $rows[] = [
                    'relation_id' => (string) $relation->id,
                    'mapping_type' => $mapping->mapping_type->value,
                    'entity_a' => $sideA,
                    'entity_b' => $sideB,
                    'difference' => $difference,
                    'tolerance' => ConsolidationMath::norm((string) $relation->tolerance_amount),
                    'has_difference' => $hasDifference,
                    'status' => $hasDifference ? 'difference' : 'matched',
                ];
            }
        }

        return [
            'group_id' => (string) $group->id,
            'fiscal_period_id' => (string) $period->id,
            'year_start' => $yearStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'currency' => $groupCurrency,
            'amounts_basis' => 'Balance sheet accounts at the period end; income accounts year to date.',
            'rows' => $rows,
            'summary' => [
                'pairs' => count($rows),
                'pairs_with_difference' => count(array_filter($rows, static fn (array $row): bool => $row['has_difference'])),
                'absolute_difference' => $this->absoluteDifference($rows),
            ],
        ];
    }

    /**
     * @param  array{tenant_id: string, currency: string, rates: array<string, string>, rows: list<array<string, mixed>>}  $translated
     * @return array{currency: string, year_start: string, by_account: array<string, array<string, mixed>>}
     */
    private function index(array $translated, CarbonImmutable $yearStart): array
    {
        $byAccount = [];

        foreach ($translated['rows'] as $row) {
            if ($row['account_id'] !== null) {
                $byAccount[$row['account_id']] = $row;
            }
        }

        return ['currency' => $translated['currency'], 'year_start' => $yearStart->toDateString(), 'by_account' => $byAccount];
    }

    /**
     * @param  array<string, array{currency: string, year_start: string, by_account: array<string, array<string, mixed>>}>  $translated
     * @param  array<string, array<string, array{code: string, name: ?string}>>  $maps
     * @return array<string, mixed>
     */
    private function side(string $tenantId, string $accountId, array $translated, mixed $names, array $maps): array
    {
        $row = $translated[$tenantId]['by_account'][$accountId] ?? null;
        $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($accountId);
        $isDebitNormal = ($row['normal_balance'] ?? $account?->normal_balance) === 'debit';
        $local = $row === null ? ConsolidationMath::zero() : ($isDebitNormal ? $row['local_net'] : ConsolidationMath::neg($row['local_net']));
        $group = $row === null ? ConsolidationMath::zero() : ($isDebitNormal ? $row['group_net'] : ConsolidationMath::neg($row['group_net']));

        return [
            'tenant_id' => $tenantId,
            'name' => (string) ($names[$tenantId] ?? $tenantId),
            'account_id' => $accountId,
            'account_code' => $maps[$tenantId][$accountId]['code'] ?? $account?->account_code,
            'account_name' => $maps[$tenantId][$accountId]['name'] ?? $account?->name,
            'year_start' => $translated[$tenantId]['year_start'],
            'local_currency' => $translated[$tenantId]['currency'],
            'local_amount' => $local,
            'group_amount' => $group,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function absoluteDifference(array $rows): array
    {
        $totals = [IntercompanyMappingType::ReceivablePayable->value => ConsolidationMath::zero(), IntercompanyMappingType::RevenueCost->value => ConsolidationMath::zero()];

        foreach ($rows as $row) {
            $totals[$row['mapping_type']] = ConsolidationMath::add($totals[$row['mapping_type']], ConsolidationMath::abs($row['difference']));
        }

        return $totals;
    }
}
