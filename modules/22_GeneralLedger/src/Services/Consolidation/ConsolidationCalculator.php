<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;
use Modules\GeneralLedger\Enums\ConsolidationAdjustmentType;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationAccountMapping;
use Modules\GeneralLedger\Models\ConsolidationAcquisition;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;
use Modules\GeneralLedger\Models\IntragroupDividend;
use Modules\GeneralLedger\Models\IntragroupTransaction;

/**
 * The accounting of a consolidation, computed in memory from the ledgers of the member entities and from the group
 * configuration; nothing is stored and no ledger is written. The concepts are those of IFRS 10 / IAS 28 / IAS 21 /
 * IFRS 3, OIC 17 and the Swiss CO art. 963 and following; the rules below are a simplification written for this
 * application, the text of those standards has not been verified for it and no compliance with any of them is
 * claimed. Accounting assumptions, in the order they apply:
 *
 * 1. Period. A run is for a fiscal period of the parent entity. Balance sheet accounts are taken at the period end and
 *    income accounts year to date (from the start of the fiscal year), so the result on the balance sheet is the result
 *    of the income statement. The fiscal year of a member starts `fiscal_year_offset_months` after the one of the
 *    parent (0 by default); the income accounts of the member are read from its own year start, so that a member with
 *    a different year contributes its own year to date, and only a member whose real fiscal year cannot be aligned to
 *    the month grid of that offset is refused (see alignedYearStart).
 * 2. Chart of accounts. Accounts are keyed by group account code: the explicit mapping of the member (account mapping
 *    table) when there is one, otherwise the code of the account itself. Accounts mapped or not, a code used with
 *    different types is reported as a warning. A group may require every account with a balance to be mapped.
 * 3. Methods. Full members are added line by line at 100 %. Proportional members are added line by line at the
 *    effective ownership percentage, with no non-controlling interests. Equity members are not added: see 9.
 *    The parent entity is always full at 100 %.
 * 4. Holdings. A member is held directly by the parent or by another fully consolidated member (`holder_tenant_id`);
 *    its effective ownership is the product of the direct percentages along the chain, and a cycle is refused. The
 *    effective percentage is the share of a proportional member and the basis of the non-controlling interests of a
 *    full one; the investment of a holder is eliminated against the direct percentage of the equity of its investee.
 * 5. Translation. See ConsolidationTranslator.
 * 6. Intercompany balances and income. For a mapped pair the group share of each balance is its (scaled) balance times
 *    the consolidated share of the other entity. With the default policy `keep_difference` the smaller of the two is
 *    eliminated on both sides and the difference stays visible as a warning; with `post_to_difference_account` both
 *    are eliminated in full and the residual is posted to the difference account of the group (a user choice, not a
 *    judgement on the cause). A balance on the wrong side is never eliminated.
 * 7. Investment against equity. The balances of all the mapped investment accounts of the investor are eliminated
 *    against the share of the mapped equity accounts of the investee (the direct percentage for a full investee, all
 *    of the already scaled amount for a proportional one). Without an acquisition record the difference goes to the
 *    consolidation difference account. With one, the acquisition-date inputs of the user (cost, book net assets,
 *    fair-value adjustments, deferred-tax rate) give goodwill = cost - acquired percentage x (book net assets +
 *    fair-value adjustments - deferred tax on them); a positive goodwill is an asset, a negative one is credited to its
 *    own equity account (its treatment differs between frameworks and is left to the user); the pre-acquisition
 *    reserves are eliminated on a synthetic account; any difference between the recorded cost and the investment
 *    balance, and the minority share of the fair-value adjustments, end on the fair-value reserve. Subsequent
 *    depreciation or realisation of the fair-value adjustments is not computed.
 * 8. Goodwill charges. The group setting `goodwill_method` is none (no charge), straight_line or impairment_only; the
 *    number of years of straight_line is an input of the group and no legal period is assumed. The charge is by whole
 *    months from the acquisition date. Impairments are inputs by fiscal year. Charges of earlier years go to a
 *    synthetic equity account (no past ledger of the group exists), the current year to the expense accounts.
 * 9. Equity method. The investment account of the investor is brought to the ownership share of the net assets of the
 *    investee; the share of its year-to-date result goes to the equity-method result account and the rest of the
 *    movement to the equity-method reserve. Dividends are not separated.
 * 10. Unrealised intragroup profit. Declared transactions (margin in group currency) up to the period end: for
 *    inventory the margin that is still in stock is eliminated from the asset and charged to the income account chosen,
 *    the part already eliminated at the start of the year to the prior-year account; for a fixed asset the margin is
 *    eliminated from the asset and the excess depreciation (margin over the useful life, by whole months) is reversed.
 *    Deferred tax on the elimination and the effect on non-controlling interests are not computed.
 * 11. Intragroup dividends of the year are eliminated from the income account of the receiver against the equity
 *    account of the payer, up to the income booked. Dividends of earlier years are already in retained earnings on
 *    both sides and are not touched.
 * 12. Non-controlling interests (full members under 100 % effective). Measured as the minority share of the net assets
 *    of the member at the period end (without its investments in consolidated subsidiaries, which its own
 *    subsidiaries measure, and with the fair-value adjustments of its acquisition), and of its year-to-date result,
 *    before any other elimination; they are a presentation split of equity and do not change the totals.
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
        $draft->yearStart = $yearStart->toDateString();
        $draft->periodEnd = $periodEnd->toDateString();
        $groupCurrency = strtoupper($group->group_currency);

        $this->loadMembers($group, $draft, $yearStart, $periodEnd, $groupCurrency);
        $this->loadMappings($group, $draft);
        $this->loadAcquisitions($group, $draft);
        $this->translationDifferences($group, $draft, $groupCurrency);
        $this->investments($group, $draft, $yearStart, $periodEnd);
        $this->intercompanyBalances($group, $draft, IntercompanyMappingType::ReceivablePayable, ConsolidationAdjustmentType::IntercompanyBalance);
        $this->intercompanyBalances($group, $draft, IntercompanyMappingType::RevenueCost, ConsolidationAdjustmentType::IntercompanyRevenueCost);
        $this->unrealisedProfit($group, $draft, $yearStart, $periodEnd);
        $this->dividends($group, $draft, $yearStart, $periodEnd);
        $this->nonControllingInterests($draft);
        $this->typeConflicts($draft);
        $this->collectExtensions($group, $draft);

        return $draft;
    }

    /**
     * SHA-256 of everything the figures depend on, in a stable order. Inputs of the extensions are added only when they
     * differ from the defaults, so a run made without them keeps the fingerprint it always had.
     */
    public function fingerprint(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): string
    {
        $payload = [
            'group' => [
                'currency' => strtoupper($group->group_currency),
                'codes' => [$group->translation_reserve_code, $group->consolidation_difference_code, $group->equity_method_result_code, $group->equity_method_reserve_code],
            ],
            'year_start' => $yearStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'members' => array_values(array_map(static fn (array $member): array => [
                'tenant_id' => $member['tenant_id'],
                'name' => $member['name'],
                'currency' => $member['currency'],
                'ownership_percentage' => $member['ownership_percentage'],
                'consolidation_method' => $member['consolidation_method'],
                'is_parent' => $member['is_parent'],
            ], $draft->members)),
            'rates' => $draft->rates,
            'mappings' => $draft->mappings,
            'balances' => array_map(static fn (array $row): array => [
                $row['member_tenant_id'], $row['account_id'], $row['account_code'], $row['account_type'], $row['local_net'], $row['rate_basis'], $row['rate'],
                ...($row['consolidated_share'] !== '100.0000' ? [$row['consolidated_share']] : []),
            ], $draft->balances),
        ];

        if ($draft->extensions !== []) {
            $payload['extensions'] = $draft->extensions;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
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

        $effective = $this->effectiveOwnership($members);
        $names = Tenant::withoutGlobalScopes()->whereIn('id', $members->pluck('member_tenant_id')->all())->pluck('name', 'id');

        foreach (ConsolidationAccountMapping::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('id')->get() as $map) {
            $draft->accountMaps[(string) $map->member_tenant_id][(string) $map->account_id] = ['code' => (string) $map->group_account_code, 'name' => $map->group_account_name];
        }

        $unmapped = [];

        foreach ($members as $member) {
            $tenantId = (string) $member->member_tenant_id;
            $isProportional = $member->consolidation_method === ConsolidationMethod::Proportional;
            $share = $isProportional ? $effective[$tenantId] : '100.0000';
            $memberYearStart = $this->alignedYearStart($member, $yearStart, $periodEnd, (string) ($names[$tenantId] ?? $tenantId), $draft);
            $translated = $this->translator->translate($tenantId, $groupCurrency, $memberYearStart, $periodEnd);

            $draft->members[$tenantId] = [
                'tenant_id' => $tenantId,
                'name' => (string) ($names[$tenantId] ?? $tenantId),
                'currency' => $translated['currency'],
                'ownership_percentage' => (string) $member->ownership_percentage,
                'consolidation_method' => $member->consolidation_method->value,
                'is_parent' => $member->is_parent,
                'holder_tenant_id' => $member->holder_tenant_id === null ? null : (string) $member->holder_tenant_id,
                'effective_ownership' => $effective[$tenantId],
                'consolidation_share' => $share,
                'fiscal_year_offset_months' => (int) $member->fiscal_year_offset_months,
                'year_start' => $memberYearStart->toDateString(),
            ];
            $draft->rates[] = ['tenant_id' => $tenantId, 'currency' => $translated['currency'], ...$translated['rates']];

            foreach ($translated['rows'] as $row) {
                $map = $row['account_id'] === null ? null : ($draft->accountMaps[$tenantId][$row['account_id']] ?? null);

                if ($map !== null) {
                    $row['account_code'] = $map['code'];
                    $row['account_name'] = $map['name'] ?? $row['account_name'];
                } elseif ($group->require_account_mapping && $row['account_id'] !== null) {
                    $unmapped[] = "{$draft->members[$tenantId]['name']}: {$row['account_code']}";
                }

                if ($share !== '100.0000') {
                    $row['group_net'] = ConsolidationMath::percentOf($row['group_net'], $share);
                }

                $balance = [
                    'member_tenant_id' => $tenantId,
                    'consolidation_method' => $member->consolidation_method->value,
                    'ownership_percentage' => (string) $member->ownership_percentage,
                    'consolidated_share' => ConsolidationMath::norm($share),
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

        if ($unmapped !== []) {
            throw new ConsolidationException('The group requires every account with a balance to be mapped to the group chart; not mapped: '.implode(', ', array_slice($unmapped, 0, 10)).(count($unmapped) > 10 ? ' and '.(count($unmapped) - 10).' more.' : '.'));
        }
    }

    /**
     * Effective ownership of every member: the product of the direct percentages along the chain of holders. The chain
     * must end at the parent, pass only through fully consolidated members and not loop.
     *
     * @param  Collection<int, ConsolidationMember>  $members
     * @return array<string, string>
     *
     * @throws ConsolidationException
     */
    private function effectiveOwnership(Collection $members): array
    {
        $byTenant = $members->keyBy(static fn (ConsolidationMember $member): string => (string) $member->member_tenant_id);
        $parentTenantId = (string) $members->first(static fn (ConsolidationMember $member): bool => $member->is_parent)->member_tenant_id;
        $result = [];

        $resolve = static function (string $tenantId, array $path) use (&$resolve, &$result, $byTenant, $parentTenantId): string {
            if (isset($result[$tenantId])) {
                return $result[$tenantId];
            }

            if (in_array($tenantId, $path, true)) {
                throw new ConsolidationException('The holdings of the group form a cycle: an entity cannot own itself, directly or through other entities.');
            }

            $member = $byTenant[$tenantId];

            if ($member->is_parent) {
                return $result[$tenantId] = '100.0000';
            }

            $holder = (string) ($member->holder_tenant_id ?? $parentTenantId);

            if (! isset($byTenant[$holder])) {
                throw new ConsolidationException('The holder of a member is not a member of the group.');
            }

            if (! $byTenant[$holder]->is_parent && $byTenant[$holder]->consolidation_method !== ConsolidationMethod::Full) {
                throw new ConsolidationException('An entity can be held only by the parent or by a fully consolidated member.');
            }

            return $result[$tenantId] = ConsolidationMath::percentOf($resolve($holder, [...$path, $tenantId]), (string) $member->ownership_percentage);
        };

        foreach ($byTenant->keys() as $tenantId) {
            $resolve((string) $tenantId, []);
        }

        return $result;
    }

    /**
     * Start of the fiscal year of a member that contains the period end: the year start of the parent shifted by the
     * offset of the member, brought back to the year that holds the period end. The fiscal years of the member are
     * checked only when an offset is set: a real year that starts on another day of the month than the shifted
     * start cannot be aligned and the run is refused, one that starts in another month is reported.
     *
     * @throws ConsolidationException
     */
    private function alignedYearStart(ConsolidationMember $member, CarbonImmutable $yearStart, CarbonImmutable $periodEnd, string $name, ConsolidationDraft $draft): CarbonImmutable
    {
        $offset = (int) $member->fiscal_year_offset_months;

        if ($offset === 0) {
            return $yearStart;
        }

        $start = $yearStart->addMonthsNoOverflow($offset);

        while ($start > $periodEnd) {
            $start = $start->subMonthsNoOverflow(12);
        }

        while ($start->addMonthsNoOverflow(12) <= $periodEnd) {
            $start = $start->addMonthsNoOverflow(12);
        }

        $actual = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $member->member_tenant_id)
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodEnd->toDateString())
            ->first();

        if ($actual !== null) {
            $actualStart = CarbonImmutable::parse($actual->start_date);

            if ($actualStart->day !== $start->day) {
                throw new ConsolidationException("The fiscal year of {$name} starts on {$actualStart->toDateString()}, which cannot be aligned with the offset of {$offset} months from the parent year ({$start->toDateString()}).");
            }

            if (! $actualStart->equalTo($start)) {
                $draft->warn('member_fiscal_year_mismatch', "The fiscal year of {$name} starts on {$actualStart->toDateString()} but the configured offset gives {$start->toDateString()}: the configured one is used.", ['member_tenant_id' => (string) $member->member_tenant_id]);
            }
        }

        return $start;
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

    private function loadAcquisitions(ConsolidationGroup $group, ConsolidationDraft $draft): void
    {
        $acquisitions = ConsolidationAcquisition::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('tenant_id', $group->tenant_id)
            ->with(['fairValueAdjustments' => static fn ($query) => $query->withoutGlobalScopes(), 'impairments' => static fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('id')
            ->get();

        foreach ($acquisitions as $acquisition) {
            $draft->acquisitions[(string) $acquisition->member_tenant_id] = [
                'acquisition_date' => $acquisition->acquisition_date->toDateString(),
                'cost' => $acquisition->cost === null ? null : (string) $acquisition->cost,
                'net_assets_at_acquisition' => (string) $acquisition->net_assets_at_acquisition,
                'acquired_percentage' => $acquisition->acquired_percentage === null ? null : (string) $acquisition->acquired_percentage,
                'recognise_deferred_tax' => (bool) $acquisition->recognise_deferred_tax,
                'deferred_tax_rate' => $acquisition->deferred_tax_rate === null ? null : (string) $acquisition->deferred_tax_rate,
                'fair_value' => $acquisition->fairValueAdjustments->map(static fn ($adjustment): array => [
                    'account_code' => (string) $adjustment->account_code,
                    'account_name' => $adjustment->account_name === null ? (string) $adjustment->account_code : (string) $adjustment->account_name,
                    'account_type' => (string) $adjustment->account_type,
                    'amount' => (string) $adjustment->amount,
                ])->all(),
                'impairments' => $acquisition->impairments->map(static fn ($impairment): array => ['fiscal_year' => (int) $impairment->fiscal_year, 'amount' => (string) $impairment->amount])->all(),
            ];
        }
    }

    private function translationDifferences(ConsolidationGroup $group, ConsolidationDraft $draft, string $groupCurrency): void
    {
        foreach ($draft->members as $tenantId => $member) {
            if (! $draft->isConsolidated($tenantId)) {
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

    private function intercompanyBalances(ConsolidationGroup $group, ConsolidationDraft $draft, IntercompanyMappingType $type, ConsolidationAdjustmentType $adjustmentType): void
    {
        foreach ($draft->mappings as $mapping) {
            if ($mapping['mapping_type'] !== $type->value || ! $this->relationUsable($draft, $mapping)) {
                continue;
            }

            [$entityA, $entityB] = [$mapping['entity_a'], $mapping['entity_b']];
            $rowA = $draft->row($entityA, $mapping['account_a_id']);
            $rowB = $draft->row($entityB, $mapping['account_b_id']);
            $amountA = ConsolidationMath::mul($draft->natural($entityA, $mapping['account_a_id']), $draft->shareFraction($entityB));
            $amountB = ConsolidationMath::mul($draft->natural($entityB, $mapping['account_b_id']), $draft->shareFraction($entityA));
            $difference = ConsolidationMath::sub($amountA, $amountB);
            $postDifference = $group->elimination_difference_policy === 'post_to_difference_account';

            if (ConsolidationMath::cmp(ConsolidationMath::abs($difference), $mapping['tolerance']) > 0) {
                $outcome = $postDifference ? 'both are eliminated and the difference is posted to the difference account' : 'the matched part is eliminated, the difference stays in the consolidated accounts';
                $draft->warn('intercompany_difference', "The balances of {$draft->memberName($entityA)} and {$draft->memberName($entityB)} on the mapped {$type->value} accounts differ by {$difference}: {$outcome}.", [
                    'relation_id' => $mapping['relation_id'],
                    'mapping_type' => $type->value,
                    'difference' => $difference,
                ]);
            }

            if ($rowA === null || $rowB === null || ! ConsolidationMath::isPositive($amountA) || ! ConsolidationMath::isPositive($amountB)) {
                continue;
            }

            $smaller = ConsolidationMath::min($amountA, $amountB);
            $eliminated = $postDifference ? [$amountA, $amountB] : [$smaller, $smaller];
            $lines = [];
            $net = ConsolidationMath::zero();

            foreach ([[$entityA, $rowA, $eliminated[0]], [$entityB, $rowB, $eliminated[1]]] as [$entity, $row, $amount]) {
                $side = $this->eliminationSide($type, $row['account_type']);

                if ($side === null) {
                    $draft->warn('mapping_account_type', "The account {$row['account_code']} of {$draft->memberName($entity)} does not fit a {$type->value} mapping and is not eliminated.", ['relation_id' => $mapping['relation_id']]);

                    continue 2;
                }

                $line = $this->rowLine($row, $side, $amount);
                $lines[] = $line;
                $net = ConsolidationMath::add($net, ConsolidationMath::sub($line['debit'], $line['credit']));
            }

            if (($lines[0]['debit'] !== '0.0000') === ($lines[1]['debit'] !== '0.0000')) {
                $draft->warn('mapping_account_type', "The two accounts of a {$type->value} mapping are on the same side and are not eliminated.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            if (! ConsolidationMath::isZero($net)) {
                $isIncome = $type === IntercompanyMappingType::RevenueCost;
                $lines[] = $this->groupLine(
                    $group->syntheticCode($isIncome ? 'intercompany_pl_difference' : 'intercompany_difference'),
                    $isIncome ? 'Intercompany difference (income)' : 'Intercompany difference',
                    $isIncome ? 'expense' : 'asset',
                    ConsolidationMath::neg($net),
                    null,
                );
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

    private function investments(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $holdings = [];

        foreach ($draft->mappings as $mapping) {
            if ($mapping['mapping_type'] !== IntercompanyMappingType::InvestmentEquity->value) {
                continue;
            }

            $holdings[$mapping['relation_id']] ??= ['mapping' => $mapping, 'investment' => [], 'equity' => []];

            if (! in_array($mapping['account_a_id'], $holdings[$mapping['relation_id']]['investment'], true)) {
                $holdings[$mapping['relation_id']]['investment'][] = $mapping['account_a_id'];
            }

            if ($mapping['account_b_id'] !== null && ! in_array($mapping['account_b_id'], $holdings[$mapping['relation_id']]['equity'], true)) {
                $holdings[$mapping['relation_id']]['equity'][] = $mapping['account_b_id'];
            }
        }

        foreach ($holdings as $holding) {
            $mapping = $holding['mapping'];

            if (! $this->relationUsable($draft, $mapping, requireConsolidated: false)) {
                continue;
            }

            [$investor, $investee] = [$mapping['entity_a'], $mapping['entity_b']];
            $method = $draft->members[$investee]['consolidation_method'];

            if (! $draft->isFull($investor)) {
                $draft->warn('investment_investor_not_consolidated', "The investor {$draft->memberName($investor)} is not fully consolidated: its investment in {$draft->memberName($investee)} is not eliminated.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            $investmentRows = [];
            $cost = ConsolidationMath::zero();

            foreach ($holding['investment'] as $accountId) {
                $row = $draft->row($investor, $accountId);
                $natural = $draft->natural($investor, $accountId);

                if ($row !== null && $row['account_type'] === 'asset' && ConsolidationMath::isPositive($natural)) {
                    $investmentRows[] = [$row, $natural];
                    $cost = ConsolidationMath::add($cost, $natural);
                }
            }

            if ($investmentRows === []) {
                $draft->warn('investment_account_empty', "The mapped investment accounts of {$draft->memberName($investor)} have no debit balance: nothing is eliminated for {$draft->memberName($investee)}.", ['relation_id' => $mapping['relation_id']]);

                continue;
            }

            if ($method === ConsolidationMethod::Equity->value) {
                $this->applyEquityMethod($group, $draft, $mapping, $investmentRows, $cost);

                continue;
            }

            $this->eliminateInvestment($group, $draft, $mapping, $investmentRows, $cost, $holding['equity'], $yearStart, $periodEnd);
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  list<array{0: array<string, mixed>, 1: string}>  $investmentRows
     * @param  list<string>  $equityAccountIds
     */
    private function eliminateInvestment(ConsolidationGroup $group, ConsolidationDraft $draft, array $mapping, array $investmentRows, string $cost, array $equityAccountIds, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        [$investor, $investee] = [$mapping['entity_a'], $mapping['entity_b']];
        $directPercentage = $draft->members[$investee]['ownership_percentage'];
        $applied = $draft->isFull($investee) ? $directPercentage : '100';
        $acquisition = $draft->acquisitions[$investee] ?? null;
        $acquired = $acquisition !== null && CarbonImmutable::parse($acquisition['acquisition_date']) <= $periodEnd;

        if ($acquisition !== null && ! $acquired) {
            $draft->warn('acquisition_after_period', "The acquisition of {$draft->memberName($investee)} is dated after the period end: goodwill is not computed.", ['member_tenant_id' => $investee]);
        }

        $lines = [];
        $shareTotal = ConsolidationMath::zero();

        foreach ($equityAccountIds as $accountId) {
            $row = $draft->row($investee, $accountId);

            if ($row === null || $row['account_type'] !== 'equity') {
                continue;
            }

            $share = ConsolidationMath::percentOf($draft->natural($investee, $accountId), $applied);

            if (ConsolidationMath::isZero($share)) {
                continue;
            }

            $shareTotal = ConsolidationMath::add($shareTotal, $share);
            $lines[] = $this->rowLine($row, ConsolidationMath::isPositive($share) ? 'debit' : 'credit', ConsolidationMath::abs($share));
        }

        if ($lines === [] && ! $acquired) {
            $draft->warn('investment_not_eliminated', "No equity account of {$draft->memberName($investee)} with a balance is mapped: the investment of {$draft->memberName($investor)} is not eliminated.", ['relation_id' => $mapping['relation_id']]);

            return;
        }

        foreach ($investmentRows as [$row, $natural]) {
            $lines[] = $this->rowLine($row, 'credit', $natural);
        }

        $draft->investmentsEliminated[$investor] = ConsolidationMath::add($draft->investmentsEliminated[$investor] ?? ConsolidationMath::zero(), $cost);

        if ($acquired) {
            $this->acquisitionLines($group, $draft, $investee, $acquisition, $cost, $shareTotal, $lines);
            $this->adjust($draft, ConsolidationAdjustmentType::Goodwill, $mapping['relation_id'], $investee, "First consolidation of {$draft->memberName($investee)}: investment of {$draft->memberName($investor)} against equity, fair value and goodwill ({$directPercentage} % held)", $lines);
            $this->goodwillCharges($group, $draft, $investee, $acquisition, $yearStart, $periodEnd);

            return;
        }

        $difference = ConsolidationMath::sub($cost, $shareTotal);

        if (! ConsolidationMath::isZero($difference)) {
            $lines[] = $this->groupLine($group->consolidation_difference_code, 'Consolidation difference', 'asset', $difference, null);
        }

        $this->adjust($draft, ConsolidationAdjustmentType::InvestmentEquity, $mapping['relation_id'], $investee, "Elimination of the investment of {$draft->memberName($investor)} against the equity of {$draft->memberName($investee)} ({$directPercentage} %)", $lines);
    }

    /**
     * Completes the elimination of an investment with the acquisition: pre-acquisition reserves, fair-value
     * adjustments, deferred tax, goodwill, and the balancing line on the fair-value reserve. `$lines` holds the equity
     * and investment lines already.
     *
     * @param  array<string, mixed>  $acquisition
     * @param  list<array<string, mixed>>  $lines
     */
    private function acquisitionLines(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, string $cost, string $shareTotal, array &$lines): void
    {
        $acquiredPercentage = $acquisition['acquired_percentage'] ?? $draft->members[$investee]['ownership_percentage'];
        $paid = $acquisition['cost'] ?? $cost;
        $bookNetAssets = $acquisition['net_assets_at_acquisition'];
        $fraction = $draft->isFull($investee) ? '100' : $draft->members[$investee]['consolidation_share'];
        $fairValueNet = ConsolidationMath::zero();

        foreach ($acquisition['fair_value'] as $adjustment) {
            $signed = $adjustment['account_type'] === 'liability' ? ConsolidationMath::neg($adjustment['amount']) : $adjustment['amount'];
            $fairValueNet = ConsolidationMath::add($fairValueNet, $signed);
        }

        $deferredTax = $acquisition['recognise_deferred_tax'] && $acquisition['deferred_tax_rate'] !== null
            ? ConsolidationMath::percentOf($fairValueNet, $acquisition['deferred_tax_rate'])
            : ConsolidationMath::zero();
        $netAssetsAtFairValue = ConsolidationMath::sub(ConsolidationMath::add($bookNetAssets, $fairValueNet), $deferredTax);
        $goodwill = ConsolidationMath::sub($paid, ConsolidationMath::percentOf($netAssetsAtFairValue, $acquiredPercentage));
        $draft->fairValueNet[$investee] = ConsolidationMath::sub($fairValueNet, $deferredTax);

        $preAcquisition = ConsolidationMath::sub(ConsolidationMath::percentOf($bookNetAssets, $acquiredPercentage), $shareTotal);
        $extra = [$this->groupLine($group->syntheticCode('preacquisition_reserves'), 'Pre-acquisition reserves', 'equity', $preAcquisition, $investee)];

        foreach ($acquisition['fair_value'] as $adjustment) {
            $scaled = ConsolidationMath::percentOf($adjustment['amount'], $fraction);
            $extra[] = $this->groupLine($adjustment['account_code'], $adjustment['account_name'], $adjustment['account_type'], $adjustment['account_type'] === 'liability' ? ConsolidationMath::neg($scaled) : $scaled, $investee);
        }

        $extra[] = $this->groupLine($group->syntheticCode('deferred_tax'), 'Deferred tax on fair-value adjustments', 'liability', ConsolidationMath::neg(ConsolidationMath::percentOf($deferredTax, $fraction)), $investee);
        $extra[] = ConsolidationMath::isPositive($goodwill) || ConsolidationMath::isZero($goodwill)
            ? $this->groupLine($group->syntheticCode('goodwill'), 'Goodwill', 'asset', $goodwill, $investee)
            : $this->groupLine($group->syntheticCode('negative_goodwill'), 'Negative goodwill', 'equity', $goodwill, $investee);

        $net = ConsolidationMath::zero();

        foreach ([...$lines, ...$extra] as $line) {
            $net = ConsolidationMath::add($net, ConsolidationMath::sub($line['debit'], $line['credit']));
        }

        $extra[] = $this->groupLine($group->syntheticCode('fair_value_reserve'), 'Fair-value reserve', 'equity', ConsolidationMath::neg($net), $investee);

        foreach ($extra as $line) {
            if (! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])) {
                $lines[] = $line;
            }
        }
    }

    /**
     * Amortisation and impairment of the goodwill of an acquisition up to the period end (assumption 8).
     *
     * @param  array<string, mixed>  $acquisition
     */
    private function goodwillCharges(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $method = $group->goodwill_method;

        if (! in_array($method, ['straight_line', 'impairment_only'], true)) {
            return;
        }

        $goodwill = null;

        foreach ($draft->adjustments as $adjustment) {
            if ($adjustment['type'] === ConsolidationAdjustmentType::Goodwill->value && $adjustment['member_tenant_id'] === $investee) {
                foreach ($adjustment['lines'] as $line) {
                    if ($line['account_code'] === $group->syntheticCode('goodwill')) {
                        $goodwill = $line['debit'];
                    }
                }
            }
        }

        if ($goodwill === null || ! ConsolidationMath::isPositive($goodwill)) {
            return;
        }

        $acquisitionDate = CarbonImmutable::parse($acquisition['acquisition_date']);
        $prior = ConsolidationMath::zero();
        $current = ConsolidationMath::zero();

        if ($method === 'straight_line') {
            $years = (int) $group->goodwill_amortisation_years;

            if ($years < 1) {
                throw new ConsolidationException('Straight-line goodwill amortisation needs the number of years of the group.');
            }

            $months = $years * 12;
            $priorMonths = $acquisitionDate < $yearStart ? min($months, $this->wholeMonths($acquisitionDate, $yearStart)) : 0;
            $currentStart = $acquisitionDate > $yearStart ? $acquisitionDate : $yearStart;
            $currentMonths = min($months - $priorMonths, $this->wholeMonths($currentStart, $periodEnd->addDay()));
            $prior = ConsolidationMath::mul($goodwill, bcdiv((string) $priorMonths, (string) $months, 10));
            $current = ConsolidationMath::mul($goodwill, bcdiv((string) $currentMonths, (string) $months, 10));
        }

        $priorImpairment = ConsolidationMath::zero();
        $currentImpairment = ConsolidationMath::zero();

        foreach ($acquisition['impairments'] as $impairment) {
            if ($impairment['fiscal_year'] < $yearStart->year) {
                $priorImpairment = ConsolidationMath::add($priorImpairment, $impairment['amount']);
            } elseif ($impairment['fiscal_year'] === $yearStart->year) {
                $currentImpairment = ConsolidationMath::add($currentImpairment, $impairment['amount']);
            }
        }

        $room = ConsolidationMath::sub(ConsolidationMath::sub(ConsolidationMath::sub($goodwill, $prior), $current), $priorImpairment);

        if (ConsolidationMath::cmp($currentImpairment, $room) > 0) {
            $draft->warn('goodwill_charges_exceed', "The goodwill charges of {$draft->memberName($investee)} would exceed the goodwill: the impairment of the year is limited to the goodwill left.", ['member_tenant_id' => $investee]);
            $currentImpairment = ConsolidationMath::isPositive($room) ? $room : ConsolidationMath::zero();
        }

        $priorTotal = ConsolidationMath::add($prior, $priorImpairment);
        $total = ConsolidationMath::add(ConsolidationMath::add($priorTotal, $current), $currentImpairment);

        if (ConsolidationMath::isZero($total)) {
            return;
        }

        $lines = [
            $this->groupLine($group->syntheticCode('goodwill_prior'), 'Goodwill charges of earlier years', 'equity', $priorTotal, $investee),
            $this->groupLine($group->syntheticCode('goodwill_amortisation'), 'Goodwill amortisation', 'expense', $current, $investee),
            $this->groupLine($group->syntheticCode('goodwill_impairment'), 'Goodwill impairment', 'expense', $currentImpairment, $investee),
            $this->groupLine($group->syntheticCode('goodwill_accumulated'), 'Accumulated goodwill amortisation and impairment', 'asset', ConsolidationMath::neg($total), $investee),
        ];

        $this->adjust($draft, ConsolidationAdjustmentType::GoodwillAmortisation, null, $investee, "Goodwill charges of {$draft->memberName($investee)} to {$periodEnd->toDateString()}", array_values(array_filter(
            $lines,
            static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])
        )));
    }

    private function wholeMonths(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $to <= $from ? 0 : (int) floor($from->diffInMonths($to));
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  list<array{0: array<string, mixed>, 1: string}>  $investmentRows
     */
    private function applyEquityMethod(ConsolidationGroup $group, ConsolidationDraft $draft, array $mapping, array $investmentRows, string $cost): void
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
            $this->rowLine($investmentRows[0][0], ConsolidationMath::isPositive($movement) || ConsolidationMath::isZero($movement) ? 'debit' : 'credit', ConsolidationMath::abs($movement)),
            $this->groupLine($group->equity_method_result_code, 'Share of profit of equity-accounted entities', 'revenue', ConsolidationMath::neg($result), $investee),
            $this->groupLine($group->equity_method_reserve_code, 'Equity-method reserve', 'equity', ConsolidationMath::neg($reserve), $investee),
        ];

        $this->adjust($draft, ConsolidationAdjustmentType::EquityMethod, $mapping['relation_id'], $investee, "Equity method: investment of {$draft->memberName($investor)} in {$draft->memberName($investee)} at {$percentage} % of net assets", array_values(array_filter(
            $netLines,
            static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])
        )));
    }

    private function unrealisedProfit(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $transactions = IntragroupTransaction::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('transaction_date')->orderBy('id')->get();

        foreach ($transactions as $transaction) {
            [$seller, $buyer] = [(string) $transaction->seller_tenant_id, (string) $transaction->buyer_tenant_id];
            $date = CarbonImmutable::parse($transaction->transaction_date);

            if ($date > $periodEnd) {
                continue;
            }

            if (! $draft->isConsolidated($seller) || ! $draft->isConsolidated($buyer)) {
                $draft->warn('transaction_entity_not_consolidated', 'An intragroup transaction involves an entity that is not consolidated line by line: it is skipped.', ['transaction_id' => (string) $transaction->id]);

                continue;
            }

            $margin = $transaction->margin_amount !== null ? (string) $transaction->margin_amount : ConsolidationMath::sub((string) $transaction->sale_price, (string) $transaction->seller_cost);
            $margin = ConsolidationMath::mul($margin, bcmul($draft->shareFraction($seller), $draft->shareFraction($buyer), 10));
            $asset = $this->accountRef($draft, $buyer, (string) $transaction->asset_account_id);
            $profit = $this->accountRef($draft, $seller, (string) $transaction->profit_account_id);

            if (! ConsolidationMath::isPositive($margin) || $asset === null || $profit === null) {
                $draft->warn('transaction_skipped', 'An intragroup transaction has no positive margin or an account that does not exist: it is skipped.', ['transaction_id' => (string) $transaction->id]);

                continue;
            }

            $priorYear = $date < $yearStart;
            $priorCode = $group->syntheticCode('prior_unrealised_profit');
            $priorName = 'Unrealised intragroup profit of earlier years';
            $lines = [];

            if ($transaction->asset_type === 'inventory') {
                $close = ConsolidationMath::percentOf($margin, (string) $transaction->remaining_percentage);
                $open = $priorYear ? ConsolidationMath::percentOf($margin, (string) ($transaction->opening_remaining_percentage ?? $transaction->remaining_percentage)) : ConsolidationMath::zero();
                $lines[] = $this->refLine($asset, $buyer, ConsolidationMath::neg($close));
                $lines[] = $this->groupLine($priorCode, $priorName, 'equity', $open, null);
                $lines[] = $this->refLine($profit, $seller, ConsolidationMath::sub($close, $open));
            } else {
                $depreciation = $transaction->depreciation_account_id === null ? null : $this->accountRef($draft, $buyer, (string) $transaction->depreciation_account_id);

                if ($depreciation === null || (int) $transaction->useful_life_years < 1) {
                    $draft->warn('transaction_skipped', 'A fixed-asset transaction needs a useful life and a depreciation account: it is skipped.', ['transaction_id' => (string) $transaction->id]);

                    continue;
                }

                $months = (int) $transaction->useful_life_years * 12;
                $total = ConsolidationMath::mul($margin, bcdiv((string) min($months, $this->wholeMonths($date, $periodEnd->addDay())), (string) $months, 10));
                $prior = $priorYear ? ConsolidationMath::mul($margin, bcdiv((string) min($months, $this->wholeMonths($date, $yearStart)), (string) $months, 10)) : ConsolidationMath::zero();
                $current = ConsolidationMath::sub($total, $prior);
                $profitDebit = ConsolidationMath::sub($margin, $prior);
                $lines[] = $priorYear
                    ? $this->groupLine($priorCode, $priorName, 'equity', $profitDebit, null)
                    : $this->refLine($profit, $seller, $profitDebit);
                $lines[] = $this->refLine($asset, $buyer, ConsolidationMath::neg(ConsolidationMath::sub($margin, $total)));
                $lines[] = $this->refLine($depreciation, $buyer, ConsolidationMath::neg($current));
            }

            $lines = array_values(array_filter($lines, static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])));

            if ($lines !== []) {
                $this->adjust($draft, ConsolidationAdjustmentType::UnrealisedProfit, null, $buyer, "Unrealised intragroup profit on {$transaction->asset_type} sold by {$draft->memberName($seller)} to {$draft->memberName($buyer)} on {$date->toDateString()}", $lines);
            }
        }
    }

    private function dividends(ConsolidationGroup $group, ConsolidationDraft $draft, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $dividends = IntragroupDividend::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('dividend_date')->orderBy('id')->get();

        foreach ($dividends as $dividend) {
            [$payer, $receiver] = [(string) $dividend->payer_tenant_id, (string) $dividend->receiver_tenant_id];
            $date = CarbonImmutable::parse($dividend->dividend_date);

            if ($date < $yearStart || $date > $periodEnd) {
                continue;
            }

            if (! $draft->isConsolidated($payer) || ! $draft->isConsolidated($receiver)) {
                $draft->warn('dividend_entity_not_consolidated', 'An intragroup dividend involves an entity that is not consolidated line by line: it is skipped.', ['dividend_id' => (string) $dividend->id]);

                continue;
            }

            $income = $this->accountRef($draft, $receiver, (string) $dividend->income_account_id);
            $equity = $this->accountRef($draft, $payer, (string) $dividend->equity_account_id);

            if ($income === null || $equity === null) {
                $draft->warn('dividend_skipped', 'An intragroup dividend refers to an account that does not exist: it is skipped.', ['dividend_id' => (string) $dividend->id]);

                continue;
            }

            $amount = ConsolidationMath::mul((string) $dividend->amount, bcmul($draft->shareFraction($payer), $draft->shareFraction($receiver), 10));
            $booked = ConsolidationMath::neg($draft->row($receiver, (string) $dividend->income_account_id)['group_net'] ?? ConsolidationMath::zero());

            if (ConsolidationMath::cmp($booked, $amount) < 0) {
                $draft->warn('dividend_income_below_dividend', "{$draft->memberName($receiver)} booked less income on the mapped account than the dividend declared: only the income booked is eliminated.", ['dividend_id' => (string) $dividend->id]);
                $amount = ConsolidationMath::isPositive($booked) ? $booked : ConsolidationMath::zero();
            }

            if (ConsolidationMath::isZero($amount)) {
                continue;
            }

            $this->adjust($draft, ConsolidationAdjustmentType::Dividend, null, $payer, "Dividend of {$draft->memberName($payer)} to {$draft->memberName($receiver)} on {$date->toDateString()}", [
                $this->refLine($income, $receiver, $amount),
                $this->refLine($equity, $payer, ConsolidationMath::neg($amount)),
            ]);
        }
    }

    private function nonControllingInterests(ConsolidationDraft $draft): void
    {
        foreach ($draft->members as $tenantId => $member) {
            if (! $draft->isFull($tenantId) || ConsolidationMath::cmp($member['effective_ownership'], '100') >= 0) {
                continue;
            }

            $minority = bcsub('100', $member['effective_ownership'], 4);
            $netAssets = ConsolidationMath::add(
                ConsolidationMath::sub($this->netAssets($draft, $tenantId), $draft->investmentsEliminated[$tenantId] ?? ConsolidationMath::zero()),
                $draft->fairValueNet[$tenantId] ?? ConsolidationMath::zero()
            );
            $profit = ConsolidationMath::neg($this->incomeNet($draft, $tenantId));

            $draft->nonControllingInterests[] = [
                'member_tenant_id' => $tenantId,
                'ownership_percentage' => $member['effective_ownership'],
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
            if (! in_array($row['consolidation_method'], [ConsolidationMethod::Full->value, ConsolidationMethod::Proportional->value], true)) {
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
     * Records the inputs of the extensions that are not at their defaults (see fingerprint()).
     */
    private function collectExtensions(ConsolidationGroup $group, ConsolidationDraft $draft): void
    {
        $extensions = [];
        $members = [];

        foreach ($draft->members as $tenantId => $member) {
            if ($member['holder_tenant_id'] !== null || $member['fiscal_year_offset_months'] !== 0 || $member['consolidation_method'] === 'proportional') {
                $members[$tenantId] = [$member['holder_tenant_id'], $member['effective_ownership'], $member['fiscal_year_offset_months'], $member['year_start'], $member['consolidation_share']];
            }
        }

        $settings = [$group->elimination_difference_policy, $group->goodwill_method, $group->goodwill_amortisation_years, (bool) $group->require_account_mapping, $group->synthetic_accounts ?? []];

        if ($members !== []) {
            $extensions['members'] = $members;
        }

        if ($settings !== ['keep_difference', 'none', null, false, []]) {
            $extensions['settings'] = $settings;
        }

        if ($draft->accountMaps !== []) {
            $extensions['account_maps'] = $draft->accountMaps;
        }

        if ($draft->acquisitions !== []) {
            $extensions['acquisitions'] = $draft->acquisitions;
        }

        foreach (['transactions' => IntragroupTransaction::class, 'dividends' => IntragroupDividend::class] as $key => $model) {
            $rows = $model::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->orderBy('id')->get();

            if ($rows->isNotEmpty()) {
                $extensions[$key] = $rows->map(static function ($row): array {
                    $attributes = $row->getAttributes();
                    unset($attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);

                    return $attributes;
                })->all();
            }
        }

        $draft->extensions = $extensions;
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
     * Both entities of the relation must be members, and consolidated line by line unless said otherwise: an
     * intercompany balance with an entity that is not added line by line is a real balance with a third party for the
     * group.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function relationUsable(ConsolidationDraft $draft, array $mapping, bool $requireConsolidated = true): bool
    {
        foreach ([$mapping['entity_a'], $mapping['entity_b']] as $tenantId) {
            if (! isset($draft->members[$tenantId])) {
                $draft->warn('relation_entity_not_member', 'An entity of an intercompany relation is no longer a member of the group: its mappings are skipped.', ['relation_id' => $mapping['relation_id']]);

                return false;
            }
        }

        if ($requireConsolidated && (! $draft->isConsolidated($mapping['entity_a']) || ! $draft->isConsolidated($mapping['entity_b']))) {
            $draft->warn('relation_entity_not_consolidated', "The mappings between {$draft->memberName($mapping['entity_a'])} and {$draft->memberName($mapping['entity_b'])} are skipped: one of them is not consolidated line by line, so the balance is with a third party for the group.", ['relation_id' => $mapping['relation_id']]);

            return false;
        }

        return true;
    }

    /**
     * The group code, name and type of an account of a member, from its balance row or, when it has no balance, from
     * its chart (with the group mapping applied).
     *
     * @return array{code: string, name: string, type: string}|null
     */
    private function accountRef(ConsolidationDraft $draft, string $tenantId, string $accountId): ?array
    {
        $row = $draft->row($tenantId, $accountId);

        if ($row !== null) {
            return ['code' => $row['account_code'], 'name' => $row['account_name'], 'type' => $row['account_type']];
        }

        $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($accountId);

        if ($account === null) {
            return null;
        }

        $map = $draft->accountMaps[$tenantId][$accountId] ?? null;

        return ['code' => $map['code'] ?? (string) $account->account_code, 'name' => $map['name'] ?? (string) $account->name, 'type' => (string) $account->account_type];
    }

    /**
     * A line on an account of a member found with accountRef(); `$net` is debit minus credit.
     *
     * @param  array{code: string, name: string, type: string}  $ref
     * @return array{entity_tenant_id: ?string, account_code: string, account_name: string, account_type: string, debit: string, credit: string}
     */
    private function refLine(array $ref, string $tenantId, string $net): array
    {
        return $this->groupLine($ref['code'], $ref['name'], $ref['type'], $net, $tenantId);
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
