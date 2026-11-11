<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use LogicException;
use Modules\GeneralLedger\Enums\ConsolidationAdjustmentType;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;

/**
 * The accounting of a consolidation, computed in memory from the ledgers of the member entities and from the group
 * configuration; nothing is stored and no ledger is written. The concepts are those of IFRS 10 / IAS 28 / IAS 21, OIC 17
 * and the Swiss CO art. 963 and following; the rules below are a simplification written for this application and no
 * compliance with any standard is claimed. Accounting assumptions, in the order they apply:
 *
 * 1. Period. A run is for a fiscal period of the parent entity. Balance sheet accounts are taken at the period end and
 *    income accounts year to date (from the start of the parent's fiscal year), so the result on the balance sheet is
 *    the result of the income statement. The fiscal year of every member is assumed to match the parent's.
 * 2. Chart of accounts. Entities may keep their own charts; the consolidated accounts are keyed by account code, so
 *    accounts of different entities with the same code are added together. The group is expected to use aligned codes
 *    for what it wants consolidated line by line; a code used with different account types in different entities is
 *    reported as a warning.
 * 3. Methods. Full members are added line by line. Equity members are not added: see 8. Proportional members are
 *    refused. The parent entity is always full at 100 %.
 * 4. Translation. See ConsolidationTranslator: closing rate for assets and liabilities, historical rate for equity,
 *    average rate for income accounts; the difference that the three rates leave in the translated trial balance of an
 *    entity is the translation difference, a balancing entry on the translation reserve of the group.
 * 5. Intercompany balances (receivable/payable). For a mapped pair the amount eliminated is the smaller of the two
 *    balances in group currency, never more than either side. A difference between the two stays in the consolidated
 *    accounts and is reported as a warning above the tolerance of the relation: it is not forced to zero and no
 *    suspense account is invented. A balance on the wrong side (a receivable that is a net credit) is not eliminated.
 * 6. Intercompany revenue and cost: the same rule on the year-to-date income accounts. Profit in stock or in fixed
 *    assets bought from another entity of the group (unrealised intragroup profit) is not eliminated, nor are
 *    intercompany dividends and loans interest beyond the mapped accounts.
 * 7. Investment against equity (full members). The whole balance of the mapped investment account of the investor is
 *    eliminated against the ownership share of the mapped equity accounts of the investee, taken in group currency. The
 *    mapped accounts are the capital accounts that existed at acquisition: later reserves and result remain in group
 *    equity. The difference is posted to the consolidation difference account of the group (debit = excess of cost,
 *    credit = excess of equity). No goodwill, fair value of net assets at acquisition or impairment is computed. One
 *    holding relation per investment account is assumed, and only direct holdings of the parent side are modelled.
 * 8. Equity method. The investment account of the investor is brought to the ownership share of the net assets of the
 *    investee (assets plus liabilities in group currency); the share of its year-to-date result goes to the
 *    equity-method result account and the rest of the movement (earlier results, translation differences, difference
 *    to cost) to the equity-method reserve. Dividends are not separated.
 * 9. Non-controlling interests (full members under 100 %). Measured as the minority share of the net assets of the
 *    member at the period end, and of its year-to-date result, before any elimination; they are a presentation split
 *    of equity and do not change the totals.
 *
 * The computation is deterministic: the same ledgers, rates and configuration give the same figures and the same input
 * fingerprint.
 */
final readonly class ConsolidationCalculator
{
    public function __construct(private ConsolidationTranslator $translator) {}

    /**
     * @throws ConsolidationException
     */
    public function compute(ConsolidationGroup $group, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): ConsolidationDraft
    {
        $draft = new ConsolidationDraft;
        $groupCurrency = strtoupper($group->group_currency);

        $this->loadMembers($group, $draft, $yearStart, $periodEnd, $groupCurrency);
        $this->loadMappings($group, $draft);
        $this->translationDifferences($group, $draft, $groupCurrency);
        $this->investments($group, $draft);
        $this->intercompanyBalances($draft, IntercompanyMappingType::ReceivablePayable, ConsolidationAdjustmentType::IntercompanyBalance);
        $this->intercompanyBalances($draft, IntercompanyMappingType::RevenueCost, ConsolidationAdjustmentType::IntercompanyRevenueCost);
        $this->nonControllingInterests($draft);
        $this->typeConflicts($draft);

        return $draft;
    }

    /**
     * SHA-256 of everything the figures depend on, in a stable order.
     */
    public function fingerprint(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): string
    {
        return hash('sha256', json_encode([
            'group' => [
                'currency' => strtoupper($group->group_currency),
                'codes' => [$group->translation_reserve_code, $group->consolidation_difference_code, $group->equity_method_result_code, $group->equity_method_reserve_code],
            ],
            'year_start' => $yearStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'members' => array_values($draft->members),
            'rates' => $draft->rates,
            'mappings' => $draft->mappings,
            'balances' => array_map(static fn (array $row): array => [
                $row['member_tenant_id'], $row['account_id'], $row['account_code'], $row['account_type'], $row['local_net'], $row['rate_basis'], $row['rate'],
            ], $draft->balances),
        ], JSON_THROW_ON_ERROR));
    }

    private function loadMembers(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd, string $groupCurrency): void
    {
        $members = ConsolidationMember::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('tenant_id', $group->tenant_id)
            ->get()
            ->sortBy(static fn (ConsolidationMember $member): string => ($member->is_parent ? '0' : '1').$member->member_tenant_id)
            ->values();

        if (! $members->contains(static fn (ConsolidationMember $member): bool => $member->is_parent)) {
            throw new ConsolidationException('The group has no parent entity.');
        }

        foreach ($members as $member) {
            if ($member->consolidation_method === ConsolidationMethod::Proportional) {
                throw new ConsolidationException('Proportional consolidation is not implemented: change the method of the member to full or equity.');
            }
        }

        $names = Tenant::withoutGlobalScopes()->whereIn('id', $members->pluck('member_tenant_id')->all())->pluck('name', 'id');

        foreach ($members as $member) {
            $tenantId = (string) $member->member_tenant_id;
            $translated = $this->translator->translate($tenantId, $groupCurrency, $yearStart, $periodEnd);

            $draft->members[$tenantId] = [
                'tenant_id' => $tenantId,
                'name' => (string) ($names[$tenantId] ?? $tenantId),
                'currency' => $translated['currency'],
                'ownership_percentage' => (string) $member->ownership_percentage,
                'consolidation_method' => $member->consolidation_method->value,
                'is_parent' => $member->is_parent,
            ];
            $draft->rates[] = ['tenant_id' => $tenantId, 'currency' => $translated['currency'], ...$translated['rates']];

            foreach ($translated['rows'] as $row) {
                $balance = [
                    'member_tenant_id' => $tenantId,
                    'consolidation_method' => $member->consolidation_method->value,
                    'ownership_percentage' => (string) $member->ownership_percentage,
                    'local_currency' => $translated['currency'],
                    ...$row,
                ];
                $draft->balances[] = $balance;

                if ($row['account_id'] !== null) {
                    $draft->index[$tenantId][$row['account_id']] = $balance;
                }

                if ($row['is_residual']) {
                    $draft->warn('unclosed_prior_results', "{$draft->members[$tenantId]['name']} has results of earlier years that are not closed into equity in its ledger; they are carried to the account ".ConsolidationTranslator::ResidualCode.'.', ['member_tenant_id' => $tenantId]);
                }
            }

            $this->warnAboutPeriod($draft, $tenantId, $periodEnd);
        }
    }

    private function warnAboutPeriod(ConsolidationDraft $draft, string $tenantId, CarbonImmutable $periodEnd): void
    {
        $period = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodEnd->toDateString())
            ->first();

        if ($period === null) {
            $draft->warn('member_period_missing', "{$draft->memberName($tenantId)} has no fiscal period on {$periodEnd->toDateString()}.", ['member_tenant_id' => $tenantId]);
        } elseif ($period->status === 'open') {
            $draft->warn('member_period_open', "The fiscal period of {$draft->memberName($tenantId)} that holds {$periodEnd->toDateString()} is still open: its figures can change.", ['member_tenant_id' => $tenantId]);
        }
    }

    private function loadMappings(ConsolidationGroup $group, ConsolidationDraft $draft): void
    {
        $relations = IntercompanyRelation::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('id')->get();

        foreach ($relations as $relation) {
            $mappings = IntercompanyAccountMapping::withoutGlobalScopes()->where('relation_id', $relation->id)->where('tenant_id', $group->tenant_id)->orderBy('id')->get();

            foreach ($mappings as $mapping) {
                $draft->mappings[] = [
                    'relation_id' => (string) $relation->id,
                    'entity_a' => (string) $relation->entity_a_tenant_id,
                    'entity_b' => (string) $relation->entity_b_tenant_id,
                    'tolerance' => (string) $relation->tolerance_amount,
                    'mapping_type' => $mapping->mapping_type->value,
                    'account_a_id' => (string) $mapping->account_a_id,
                    'account_b_id' => $mapping->account_b_id === null ? null : (string) $mapping->account_b_id,
                ];
            }
        }
    }

    private function translationDifferences(ConsolidationGroup $group, ConsolidationDraft $draft, string $groupCurrency): void
    {
        foreach ($draft->members as $tenantId => $member) {
            if (! $draft->isFull($tenantId)) {
                continue;
            }

            $sum = ConsolidationMath::zero();

            foreach ($draft->balances as $row) {
                if ($row['member_tenant_id'] === $tenantId) {
                    $sum = ConsolidationMath::add($sum, $row['group_net']);
                }
            }

            if (ConsolidationMath::isZero($sum)) {
                continue;
            }

            $net = ConsolidationMath::neg($sum);
            $this->adjust($draft, ConsolidationAdjustmentType::TranslationDifference, null, $tenantId, "Translation difference of {$member['name']} ({$member['currency']} to {$groupCurrency})", [
                $this->groupLine($group->translation_reserve_code, 'Translation reserve', 'equity', $net, $tenantId),
            ], balancing: true);
        }
    }

    private function intercompanyBalances(ConsolidationDraft $draft, IntercompanyMappingType $type, ConsolidationAdjustmentType $adjustmentType): void
    {
        foreach ($draft->mappings as $mapping) {
            if ($mapping['mapping_type'] !== $type->value || ! $this->relationUsable($draft, $mapping)) {
                continue;
            }

            [$entityA, $entityB] = [$mapping['entity_a'], $mapping['entity_b']];
            $rowA = $draft->row($entityA, $mapping['account_a_id']);
            $rowB = $draft->row($entityB, $mapping['account_b_id']);
            $naturalA = $draft->natural($entityA, $mapping['account_a_id']);
            $naturalB = $draft->natural($entityB, $mapping['account_b_id']);
            $difference = ConsolidationMath::sub($naturalA, $naturalB);

            if (ConsolidationMath::cmp(ConsolidationMath::abs($difference), $mapping['tolerance']) > 0) {
                $draft->warn('intercompany_difference', "The balances of {$draft->memberName($entityA)} and {$draft->memberName($entityB)} on the mapped {$type->value} accounts differ by {$difference}: the matched part is eliminated, the difference stays in the consolidated accounts.", [
                    'relation_id' => $mapping['relation_id'],
                    'mapping_type' => $type->value,
                    'difference' => $difference,
                ]);
            }

            if ($rowA === null || $rowB === null || ! ConsolidationMath::isPositive($naturalA) || ! ConsolidationMath::isPositive($naturalB)) {
                continue;
            }

            $amount = ConsolidationMath::min($naturalA, $naturalB);
            $lines = [];

            foreach ([[$entityA, $rowA], [$entityB, $rowB]] as [$entity, $row]) {
                $side = $this->eliminationSide($type, $row['account_type']);

                if ($side === null) {
                    $draft->warn('mapping_account_type', "The account {$row['account_code']} of {$draft->memberName($entity)} does not fit a {$type->value} mapping and is not eliminated.", ['relation_id' => $mapping['relation_id']]);

                    continue 2;
                }

                $lines[] = $this->rowLine($row, $side, $amount);
            }

            if (($lines[0]['debit'] !== '0.0000') === ($lines[1]['debit'] !== '0.0000')) {
                $draft->warn('mapping_account_type', "The two accounts of a {$type->value} mapping are on the same side and are not eliminated.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            $this->adjust($draft, $adjustmentType, $mapping['relation_id'], null, "Elimination of {$type->value} between {$draft->memberName($entityA)} and {$draft->memberName($entityB)}", $lines);
        }
    }

    /**
     * The side on which an account is eliminated: the elimination reduces the balance the account carries.
     */
    private function eliminationSide(IntercompanyMappingType $type, string $accountType): ?string
    {
        return match ($type) {
            IntercompanyMappingType::ReceivablePayable => match ($accountType) {
                'asset' => 'credit',
                'liability' => 'debit',
                default => null,
            },
            IntercompanyMappingType::RevenueCost => match ($accountType) {
                'revenue' => 'debit',
                'cogs', 'expense' => 'credit',
                default => null,
            },
            default => null,
        };
    }

    private function investments(ConsolidationGroup $group, ConsolidationDraft $draft): void
    {
        $holdings = [];

        foreach ($draft->mappings as $mapping) {
            if ($mapping['mapping_type'] !== IntercompanyMappingType::InvestmentEquity->value) {
                continue;
            }

            $key = $mapping['relation_id'].'|'.$mapping['account_a_id'];
            $holdings[$key] ??= ['mapping' => $mapping, 'equity' => []];

            if ($mapping['account_b_id'] !== null && ! in_array($mapping['account_b_id'], $holdings[$key]['equity'], true)) {
                $holdings[$key]['equity'][] = $mapping['account_b_id'];
            }
        }

        foreach ($holdings as $holding) {
            $mapping = $holding['mapping'];

            if (! $this->relationUsable($draft, $mapping, requireFull: false)) {
                continue;
            }

            [$investor, $investee] = [$mapping['entity_a'], $mapping['entity_b']];
            $method = $draft->members[$investee]['consolidation_method'];

            if (! $draft->isFull($investor)) {
                $draft->warn('investment_investor_not_consolidated', "The investor {$draft->memberName($investor)} is not fully consolidated: its investment in {$draft->memberName($investee)} is not eliminated.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            $investmentRow = $draft->row($investor, $mapping['account_a_id']);
            $cost = $draft->natural($investor, $mapping['account_a_id']);

            if ($investmentRow === null || ! ConsolidationMath::isPositive($cost) || $investmentRow['account_type'] !== 'asset') {
                $draft->warn('investment_account_empty', "The mapped investment account of {$draft->memberName($investor)} has no debit balance: nothing is eliminated for {$draft->memberName($investee)}.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            if ($method === ConsolidationMethod::Full->value) {
                $this->eliminateInvestment($group, $draft, $mapping, $investmentRow, $cost, $holding['equity']);
            } elseif ($method === ConsolidationMethod::Equity->value) {
                $this->applyEquityMethod($group, $draft, $mapping, $investmentRow, $cost);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $investmentRow
     * @param  list<string>  $equityAccountIds
     */
    private function eliminateInvestment(ConsolidationGroup $group, ConsolidationDraft $draft, array $mapping, array $investmentRow, string $cost, array $equityAccountIds): void
    {
        [$investor, $investee] = [$mapping['entity_a'], $mapping['entity_b']];
        $percentage = $draft->members[$investee]['ownership_percentage'];
        $lines = [];
        $shareTotal = ConsolidationMath::zero();

        foreach ($equityAccountIds as $accountId) {
            $row = $draft->row($investee, $accountId);

            if ($row === null || $row['account_type'] !== 'equity') {
                continue;
            }

            $share = ConsolidationMath::percentOf($draft->natural($investee, $accountId), $percentage);

            if (ConsolidationMath::isZero($share)) {
                continue;
            }

            $shareTotal = ConsolidationMath::add($shareTotal, $share);
            $lines[] = $this->rowLine($row, ConsolidationMath::isPositive($share) ? 'debit' : 'credit', ConsolidationMath::abs($share));
        }

        if ($lines === []) {
            $draft->warn('investment_not_eliminated', "No equity account of {$draft->memberName($investee)} with a balance is mapped: the investment of {$draft->memberName($investor)} is not eliminated.", ['relation_id' => $mapping['relation_id']]);

            return;
        }

        $lines[] = $this->rowLine($investmentRow, 'credit', $cost);
        $difference = ConsolidationMath::sub($cost, $shareTotal);

        if (! ConsolidationMath::isZero($difference)) {
            $lines[] = $this->groupLine($group->consolidation_difference_code, 'Consolidation difference', 'asset', $difference, null);
        }

        $this->adjust($draft, ConsolidationAdjustmentType::InvestmentEquity, $mapping['relation_id'], $investee, "Elimination of the investment of {$draft->memberName($investor)} against the equity of {$draft->memberName($investee)} ({$percentage} %)", $lines);
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $investmentRow
     */
    private function applyEquityMethod(ConsolidationGroup $group, ConsolidationDraft $draft, array $mapping, array $investmentRow, string $cost): void
    {
        [$investor, $investee] = [$mapping['entity_a'], $mapping['entity_b']];
        $percentage = $draft->members[$investee]['ownership_percentage'];
        $share = ConsolidationMath::percentOf($this->netAssets($draft, $investee), $percentage);
        $result = ConsolidationMath::percentOf(ConsolidationMath::neg($this->incomeNet($draft, $investee)), $percentage);
        $movement = ConsolidationMath::sub($share, $cost);

        if (ConsolidationMath::isZero($movement) && ConsolidationMath::isZero($result)) {
            return;
        }

        $reserve = ConsolidationMath::sub($movement, $result);
        $netLines = [
            $this->rowLine($investmentRow, ConsolidationMath::isPositive($movement) || ConsolidationMath::isZero($movement) ? 'debit' : 'credit', ConsolidationMath::abs($movement)),
            $this->groupLine($group->equity_method_result_code, 'Share of profit of equity-accounted entities', 'revenue', ConsolidationMath::neg($result), $investee),
            $this->groupLine($group->equity_method_reserve_code, 'Equity-method reserve', 'equity', ConsolidationMath::neg($reserve), $investee),
        ];

        $this->adjust($draft, ConsolidationAdjustmentType::EquityMethod, $mapping['relation_id'], $investee, "Equity method: investment of {$draft->memberName($investor)} in {$draft->memberName($investee)} at {$percentage} % of net assets", array_values(array_filter(
            $netLines,
            static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])
        )));
    }

    private function nonControllingInterests(ConsolidationDraft $draft): void
    {
        foreach ($draft->members as $tenantId => $member) {
            if (! $draft->isFull($tenantId) || ConsolidationMath::cmp($member['ownership_percentage'], '100') >= 0) {
                continue;
            }

            $minority = bcsub('100', $member['ownership_percentage'], 4);
            $netAssets = $this->netAssets($draft, $tenantId);
            $profit = ConsolidationMath::neg($this->incomeNet($draft, $tenantId));

            $draft->nonControllingInterests[] = [
                'member_tenant_id' => $tenantId,
                'ownership_percentage' => $member['ownership_percentage'],
                'net_assets' => $netAssets,
                'profit' => $profit,
                'nci_net_assets' => ConsolidationMath::percentOf($netAssets, $minority),
                'nci_profit' => ConsolidationMath::percentOf($profit, $minority),
            ];
        }
    }

    private function typeConflicts(ConsolidationDraft $draft): void
    {
        $types = [];

        foreach ($draft->balances as $row) {
            if ($row['consolidation_method'] !== ConsolidationMethod::Full->value) {
                continue;
            }

            $known = $types[$row['account_code']] ?? null;

            if ($known === null) {
                $types[$row['account_code']] = $row['account_type'];
            } elseif ($known !== $row['account_type']) {
                $draft->warn('account_type_conflict', "The account code {$row['account_code']} has the type {$known} in one entity and {$row['account_type']} in another: the first one is used.", ['account_code' => $row['account_code']]);
                $types[$row['account_code']] = $known;
            }
        }
    }

    /**
     * Assets plus liabilities of a member in group currency (debit minus credit), which is its net assets.
     */
    private function netAssets(ConsolidationDraft $draft, string $tenantId): string
    {
        $sum = ConsolidationMath::zero();

        foreach ($draft->balances as $row) {
            if ($row['member_tenant_id'] === $tenantId && in_array($row['account_type'], ['asset', 'wip', 'liability'], true)) {
                $sum = ConsolidationMath::add($sum, $row['group_net']);
            }
        }

        return $sum;
    }

    /**
     * Debit minus credit of the income accounts of a member in group currency: negative for a profit.
     */
    private function incomeNet(ConsolidationDraft $draft, string $tenantId): string
    {
        $sum = ConsolidationMath::zero();

        foreach ($draft->balances as $row) {
            if ($row['member_tenant_id'] === $tenantId && in_array($row['account_type'], ['revenue', 'cogs', 'expense'], true)) {
                $sum = ConsolidationMath::add($sum, $row['group_net']);
            }
        }

        return $sum;
    }

    /**
     * Both entities of the relation must be members, and fully consolidated unless said otherwise: an intercompany
     * balance with an entity that is not added line by line is a real balance with a third party for the group.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function relationUsable(ConsolidationDraft $draft, array $mapping, bool $requireFull = true): bool
    {
        foreach ([$mapping['entity_a'], $mapping['entity_b']] as $tenantId) {
            if (! isset($draft->members[$tenantId])) {
                $draft->warn('relation_entity_not_member', 'An entity of an intercompany relation is no longer a member of the group: its mappings are skipped.', ['relation_id' => $mapping['relation_id']]);

                return false;
            }
        }

        if ($requireFull && (! $draft->isFull($mapping['entity_a']) || ! $draft->isFull($mapping['entity_b']))) {
            $draft->warn('relation_entity_not_consolidated', "The mappings between {$draft->memberName($mapping['entity_a'])} and {$draft->memberName($mapping['entity_b'])} are skipped: one of them is not fully consolidated, so the balance is with a third party for the group.", ['relation_id' => $mapping['relation_id']]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{entity_tenant_id: ?string, account_code: string, account_name: string, account_type: string, debit: string, credit: string}
     */
    private function rowLine(array $row, string $side, string $amount): array
    {
        return [
            'entity_tenant_id' => $row['member_tenant_id'],
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'account_type' => $row['account_type'],
            'debit' => $side === 'debit' ? ConsolidationMath::norm($amount) : ConsolidationMath::zero(),
            'credit' => $side === 'credit' ? ConsolidationMath::norm($amount) : ConsolidationMath::zero(),
        ];
    }

    /**
     * A line on a synthetic account of the group; the signed `$net` is debit minus credit.
     *
     * @return array{entity_tenant_id: ?string, account_code: string, account_name: string, account_type: string, debit: string, credit: string}
     */
    private function groupLine(string $code, string $name, string $type, string $net, ?string $entity): array
    {
        $isDebit = ConsolidationMath::cmp($net, '0') > 0;

        return [
            'entity_tenant_id' => $entity,
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'debit' => $isDebit ? ConsolidationMath::norm($net) : ConsolidationMath::zero(),
            'credit' => $isDebit ? ConsolidationMath::zero() : ConsolidationMath::abs($net),
        ];
    }

    /**
     * Adds an adjustment. A `balancing` adjustment holds the one line that closes the translated trial balance of an
     * entity, so it is not a balanced set by itself: the lines of the member it balances are counted with it.
     *
     * @param  list<array{entity_tenant_id: ?string, account_code: string, account_name: string, account_type: string, debit: string, credit: string}>  $lines
     */
    private function adjust(ConsolidationDraft $draft, ConsolidationAdjustmentType $type, ?string $relationId, ?string $memberTenantId, string $description, array $lines, bool $balancing = false): void
    {
        $debit = ConsolidationMath::zero();
        $credit = ConsolidationMath::zero();

        foreach ($lines as $line) {
            $debit = ConsolidationMath::add($debit, $line['debit']);
            $credit = ConsolidationMath::add($credit, $line['credit']);
        }

        if (! $balancing && ConsolidationMath::cmp($debit, $credit) !== 0) {
            throw new LogicException("The consolidation adjustment [{$description}] is not balanced.");
        }

        $draft->adjustments[] = [
            'type' => $type->value,
            'relation_id' => $relationId,
            'member_tenant_id' => $memberTenantId,
            'description' => $description,
            'amount' => ConsolidationMath::abs(ConsolidationMath::sub($debit, $credit)) === '0.0000' ? $debit : ConsolidationMath::abs(ConsolidationMath::sub($debit, $credit)),
            'lines' => $lines,
        ];
    }
}
