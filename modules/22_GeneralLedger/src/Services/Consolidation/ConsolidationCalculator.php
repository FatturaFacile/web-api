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
 * configuration; nothing is stored and no ledger is written.
 *
 * SOURCES READ (on 2026-11-11) and what they were used for. No compliance with any framework is claimed beyond the
 * rules listed as verified; everything else is a simplification written for this application.
 *
 * - OIC 17 "Bilancio consolidato e metodo del patrimonio netto", edition of August 2014, read in full from
 *   https://fondazioneoic.eu/wp-content/uploads/2024/09/2014-08-05-OIC-17-Bilancio-consolidato-e-metodo-del-patrimonio-netto-2.pdf
 *   (later editions may exist and were not checked). Rules taken from it, by paragraph of that edition: 45 (intragroup
 *   balances and transactions are eliminated in full, also with minority interests); 47 (transactions are eliminated
 *   only if after the consolidation date of the investment: implemented, a transaction dated before the acquisition
 *   record of one of its parties is skipped); 48-50 (unrealised profit is removed from the asset and charged to the
 *   income statement, on the gross profit); 51 (deferred tax on that elimination: implemented as a group option with
 *   the rate as an input); 53 (eliminated in full before the minority share is measured); 54-56 (sales from the parent
 *   to a subsidiary do not touch the minority interests; sales from a subsidiary, or between subsidiaries, charge the
 *   result to majority and minority in proportion to their holding: implemented); 62-70 and 99 (the difference
 *   between cost and the book equity share at the acquisition date is allocated to identifiable assets and
 *   liabilities, with deferred tax, up to current values, and the residual is the consolidation difference, i.e.
 *   goodwill; the allocation is not attributed to the minority shareholders: implemented, so fair-value adjustments
 *   enter at the percentage acquired); 71-72 (a negative difference left after the allocation is a consolidation
 *   reserve: implemented; paragraph 73 and 74, the consolidation provision for future risks and charges for the part
 *   of the negative difference that the user declares as expected unfavourable results, used over the years the
 *   user enters whether or not the losses occur, with its use shown on its own income line, and the residual to the
 *   reserve: implemented from explicit inputs, the standard does not say how to estimate them); 75-77 (the adjustments continue
 *   while the assets exist and depreciation is computed on the adjusted value from the acquisition date, with deferred
 *   tax: implemented for the useful lives the user enters); 90 (intragroup dividends are eliminated); 96-98 (minority
 *   equity and result come from the book figures of the subsidiary adjusted for the intragroup eliminations);
 *   117-122 (proportional method: the group share of every line, only the group share of equity against the
 *   investment, eliminations on a proportional basis; the method needs joint control and at least 20 % of the
 *   votes, 10 % if listed, paragraph 117: reported as a warning, not enforced); 125-126 and 130 (assets and
 *   liabilities at the spot rate of the balance sheet date, income at the rate of the transaction or the average rate,
 *   the net effect in the translation reserve; intragroup balances compared at the closing rate: used by the
 *   translation and the reconciliation). Paragraphs 40-42 require a member with another year end to be included on the
 *   basis of an annual interim statement at the consolidation date, i.e. twelve months to that date: implemented as the
 *   member option `consolidation_basis = interim_twelve_months` (income of the twelve months ending at the period end,
 *   balances at that date, both built from the member ledger by date range, nothing posted); the default basis,
 *   `own_year`, instead reads the income accounts of the member from the start of its own fiscal year (offset) to the
 *   period end, which is a documented difference from the standard. The interim basis suits a consolidation at a year
 *   end; for a shorter period of the parent the twelve months of the member and the year to date of the parent differ.
 *   Results of the member before the twelve months that are not closed in its ledger are carried to CONS-RETAINED.
 *
 *   Out of scope, said here so as not to imply otherwise: deferred tax on the elimination of intragroup dividends and
 *   on translation differences is not computed. Paragraph 90 only requires intragroup dividends to be eliminated and
 *   the text read does not state an effect of upstream dividends on the minority interests, so none is implemented.
 * - Swiss Code of Obligations art. 963, 963a, 963b, in the text of the amendment of 23 December 2011 published in the
 *   Federal Gazette (https://fedlex.data.admin.ch/filestore/fedlex.data.admin.ch/eli/fga/2012/10/de/pdf-a/fedlex-data-admin-ch-eli-fga-2012-10-de-pdf-a.pdf);
 *   the consolidated text on fedlex.admin.ch could not be read (the site is a script application) and the thresholds or
 *   wording in force may differ. These articles state the duty to prepare consolidated accounts for controlled
 *   undertakings, the criteria of control (majority of votes, right to appoint the majority of the board, controlling
 *   influence by statute or contract), the exemptions and the recognised standards; for other undertakings they
 *   require the principles of proper accounting and the disclosure of the valuation rules (art. 963b para. 3). They
 *   state no rule on eliminations, goodwill or translation, so nothing below is derived from them.
 * - IFRS 3 and IFRS 10: only the public summary pages of ifrs.org were readable (goodwill as the residual of the cost
 *   after allocating fair values; a bargain purchase to profit or loss at once, which differs from OIC 17 para. 72
 *   and is NOT implemented). The full standards are not publicly reachable, and the wording of IAS 21 para. 47
 *   (goodwill and fair-value adjustments as assets of the foreign operation at the closing rate) was seen only in
 *   secondary commentary, so it is NOT verified: the choice between keeping goodwill at the historical rate and
 *   retranslating it at the closing rate is left to a group option.
 *
 * Accounting assumptions, in the order they apply:
 *
 * 1. Period. A run is for a fiscal period of the parent entity. Balance sheet accounts are taken at the period end and
 *    income accounts year to date (from the start of the fiscal year), so the result on the balance sheet is the result
 *    of the income statement. The fiscal year of a member starts `fiscal_year_offset_months` after the one of the
 *    parent (0 by default); the income accounts of the member are read from its own year start, and only a member whose
 *    real fiscal year cannot be aligned to the month grid of that offset is refused (see alignedYearStart).
 * 2. Chart of accounts. Accounts are keyed by group account code: the explicit mapping of the member (account mapping
 *    table) when there is one, otherwise the code of the account itself. A code used with different types is reported
 *    as a warning. A group may require every account with a balance to be mapped.
 * 3. Methods. Full members are added line by line at 100 %. Proportional members are added line by line at the
 *    effective ownership percentage, with no non-controlling interests. Equity members are not added: see 9.
 *    The parent entity is always full at 100 %.
 * 4. Holdings. A member is held directly by the parent or by another fully consolidated member (`holder_tenant_id`);
 *    its effective ownership is the product of the direct percentages along the chain, and a cycle is refused. The
 *    effective percentage is the share of a proportional member and the basis of the non-controlling interests of a
 *    full one; the investment of a holder is eliminated against the direct percentage of the equity of its investee.
 * 5. Translation. See ConsolidationTranslator. Equity at the historical rate is not stated by OIC 17 and is an
 *    approximation (one rate for every equity account).
 * 6. Intercompany balances and income. For a mapped pair the group share of each balance is its (scaled) balance times
 *    the consolidated share of the other entity. With the default policy `keep_difference` the smaller of the two is
 *    eliminated on both sides and the difference stays visible as a warning; with `post_to_difference_account` both
 *    are eliminated in full and the residual is posted to the difference account of the group (a user choice, not a
 *    judgement on the cause). A balance on the wrong side is never eliminated.
 * 7. Investment against equity. The balances of all the mapped investment accounts of the investor are eliminated
 *    against the share of the mapped equity accounts of the investee (the direct percentage for a full investee, all
 *    of the already scaled amount for a proportional one). Without an acquisition record the difference goes to the
 *    consolidation difference account. With one, the acquisition-date inputs of the user (cost, book net assets,
 *    fair-value adjustments at 100 %, deferred-tax rate) give goodwill = cost - acquired percentage x (book net assets
 *    + fair-value adjustments - deferred tax on them); the fair-value adjustments and their deferred tax are recognised
 *    at the acquired percentage only; the pre-acquisition reserves are eliminated on a synthetic account; any difference
 *    between the recorded cost and the investment balance ends on the fair-value reserve. Amounts may be entered in a
 *    local currency and are translated at the rate of the acquisition date; the group option `goodwill_translation`
 *    keeps goodwill and fair value at that historical rate (default) or retranslates their balance at the closing rate
 *    of the period against the translation reserve (the later charges stay at the historical rate).
 * 8. Goodwill charges. The group setting `goodwill_method` is none (no charge), straight_line or impairment_only; the
 *    number of years of straight_line is an input of the group and no legal period is assumed. The charge is by whole
 *    months from the acquisition date. Impairments are inputs by fiscal year, in group currency. Charges of earlier
 *    years go to a synthetic equity account (no past ledger of the group exists), the current year to the expense
 *    accounts.
 * 8b. Fair-value release. A fair-value adjustment with a useful life is released straight-line by whole months, on
 *    the recognised amount, from the acquisition date (`pro_rata`, the wording of OIC 17 para. 77) or, as an option,
 *    from the start of the fiscal year of the acquisition (`full_year`); the deferred tax recognised on it unwinds with
 *    it; goodwill is not recomputed. The release does not reach the minority interests (para. 99).
 * 9. Equity method. The investment account of the investor is brought to the ownership share of the net assets of the
 *    investee; the share of its year-to-date result goes to the equity-method result account and the rest of the
 *    movement to the equity-method reserve. Dividends are not separated.
 * 10. Unrealised intragroup profit. Declared transactions (margin in group currency, on the gross profit) up to the
 *    period end: for inventory the margin that is still in stock is eliminated from the asset and charged to the
 *    income account chosen, the part already eliminated at the start of the year to the prior-year account; for a fixed
 *    asset the margin is eliminated from the asset and the excess depreciation (margin over the useful life, by whole
 *    months) is reversed. With the group toggle on, deferred tax at the rate of the buying member (or the group rate)
 *    is recognised on the part still in the asset, the P&L side in the income tax line; the rate of the buyer is a
 *    choice made here, not verified in a source. A sale from a subsidiary that is not wholly owned (or between
 *    subsidiaries) charges the minority interests with its share of the unrealised profit net of tax; a sale from the
 *    parent does not touch them.
 * 11. Intragroup dividends of the year are eliminated from the income account of the receiver against the equity
 *    account of the payer, up to the income booked. Dividends of earlier years are already in retained earnings on
 *    both sides and are not touched.
 * 12. Non-controlling interests (full members under 100 % effective). Measured as the minority share of the net assets
 *    of the member at the period end (without its investments in consolidated subsidiaries, which its own
 *    subsidiaries measure, and after the unrealised profit it sold upstream) and of its year-to-date result, before any
 *    other elimination; fair-value adjustments are not attributed to them; they are a presentation split of equity
 *    and do not change the totals.
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
                'tax_rate' => $member->tax_rate === null ? null : (string) $member->tax_rate,
                'consolidation_basis' => (string) $member->consolidation_basis,
            ];

            if ($isProportional && ConsolidationMath::cmp((string) $member->ownership_percentage, '20') < 0) {
                $draft->warn('proportional_below_threshold', "{$draft->members[$tenantId]['name']} is consolidated proportionally with a holding under 20 %: OIC 17 para. 117 allows it for joint control of at least 20 % (10 % if listed). This is reported, not enforced.", ['member_tenant_id' => $tenantId]);
            }
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
        if ($member->consolidation_basis === 'interim_twelve_months') {
            return $periodEnd->subMonthsNoOverflow(12)->addDay();
        }

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

    /**
     * The start of the fiscal year of a member for a period end, with the same offset and alignment rules as a run,
     * for the reports that must read an entity like the run does.
     *
     * @throws ConsolidationException
     */
    public function memberYearStart(ConsolidationGroup $group, string $memberTenantId, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): CarbonImmutable
    {
        $member = ConsolidationMember::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->where('member_tenant_id', $memberTenantId)->first();

        if ($member === null) {
            return $yearStart;
        }

        $name = (string) (Tenant::withoutGlobalScopes()->whereKey($memberTenantId)->value('name') ?? $memberTenantId);

        return $this->alignedYearStart($member, $yearStart, $periodEnd, $name, new ConsolidationDraft);
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
                'input_currency' => $acquisition->input_currency,
                'fair_value' => $acquisition->fairValueAdjustments->map(static fn ($adjustment): array => [
                    'account_code' => (string) $adjustment->account_code,
                    'account_name' => $adjustment->account_name === null ? (string) $adjustment->account_code : (string) $adjustment->account_name,
                    'account_type' => (string) $adjustment->account_type,
                    'amount' => (string) $adjustment->amount,
                    'useful_life_years' => $adjustment->useful_life_years,
                ])->all(),
                'impairments' => $acquisition->impairments->map(static fn ($impairment): array => ['fiscal_year' => (int) $impairment->fiscal_year, 'amount' => (string) $impairment->amount])->all(),
            ];

            if ($acquisition->expected_future_charges !== null) {
                $draft->acquisitions[(string) $acquisition->member_tenant_id]['expected_future_charges'] = (string) $acquisition->expected_future_charges;
                $draft->acquisitions[(string) $acquisition->member_tenant_id]['provision_release_years'] = (int) $acquisition->provision_release_years;
            }
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

        if ($acquired) {
            $acquisition = $this->inGroupCurrency($group, $acquisition);
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
            $retranslation = $this->acquisitionLines($group, $draft, $investee, $acquisition, $cost, $shareTotal, $lines, $yearStart, $periodEnd);
            $this->adjust($draft, ConsolidationAdjustmentType::Goodwill, $mapping['relation_id'], $investee, "First consolidation of {$draft->memberName($investee)}: investment of {$draft->memberName($investor)} against equity, fair value and goodwill ({$directPercentage} % held)", $lines);

            if ($retranslation !== []) {
                $this->adjust($draft, ConsolidationAdjustmentType::TranslationDifference, $mapping['relation_id'], $investee, "Retranslation of goodwill and fair-value adjustments of {$draft->memberName($investee)} at the closing rate", $retranslation);
            }

            $this->goodwillCharges($group, $draft, $investee, $acquisition, $yearStart, $periodEnd);
            $this->fairValueRelease($group, $draft, $investee, $acquisition, $yearStart, $periodEnd);
            $this->provisionRelease($group, $draft, $investee, $acquisition, $yearStart, $periodEnd);

            return;
        }

        $difference = ConsolidationMath::sub($cost, $shareTotal);

        if (! ConsolidationMath::isZero($difference)) {
            $lines[] = $this->groupLine($group->consolidation_difference_code, 'Consolidation difference', 'asset', $difference, null);
        }

        $this->adjust($draft, ConsolidationAdjustmentType::InvestmentEquity, $mapping['relation_id'], $investee, "Elimination of the investment of {$draft->memberName($investor)} against the equity of {$draft->memberName($investee)} ({$directPercentage} %)", $lines);
    }

    /**
     * The acquisition inputs in group currency: amounts entered in another currency are translated at the rate of the
     * acquisition date, and the rate is kept (`rate`) for the retranslation option. Impairments are always in group
     * currency.
     *
     * @param  array<string, mixed>  $acquisition
     * @return array<string, mixed>
     *
     * @throws ConsolidationException
     */
    private function inGroupCurrency(ConsolidationGroup $group, array $acquisition): array
    {
        $currency = $acquisition['input_currency'];
        $groupCurrency = strtoupper($group->group_currency);
        $acquisition['rate'] = '1.000000';

        if ($currency === null || $currency === $groupCurrency) {
            return $acquisition;
        }

        $rate = $this->translator->rateOn($currency, $groupCurrency, CarbonImmutable::parse($acquisition['acquisition_date']));
        $acquisition['rate'] = $rate;

        foreach (['cost', 'net_assets_at_acquisition', 'expected_future_charges'] as $key) {
            if (($acquisition[$key] ?? null) !== null) {
                $acquisition[$key] = ConsolidationMath::mul($acquisition[$key], $rate);
            }
        }

        foreach ($acquisition['fair_value'] as $index => $adjustment) {
            $acquisition['fair_value'][$index]['amount'] = ConsolidationMath::mul($adjustment['amount'], $rate);
        }

        return $acquisition;
    }

    /**
     * Completes the elimination of an investment with the acquisition: pre-acquisition reserves, fair-value
     * adjustments and deferred tax at the percentage acquired (OIC 17 para. 99), goodwill, and the balancing line on the
     * fair-value reserve. `$lines` holds the equity and investment lines already. Returns the lines of the retranslation
     * of goodwill and fair value at the closing rate when the group asks for it and the inputs are in a local currency,
     * otherwise an empty list.
     *
     * @param  array<string, mixed>  $acquisition
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function acquisitionLines(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, string $cost, string $shareTotal, array &$lines, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): array
    {
        $acquiredPercentage = $acquisition['acquired_percentage'] ?? $draft->members[$investee]['ownership_percentage'];
        $paid = $acquisition['cost'] ?? $cost;
        $bookNetAssets = $acquisition['net_assets_at_acquisition'];
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

        $preAcquisition = ConsolidationMath::sub(ConsolidationMath::percentOf($bookNetAssets, $acquiredPercentage), $shareTotal);
        $extra = [$this->groupLine($group->syntheticCode('preacquisition_reserves'), 'Pre-acquisition reserves', 'equity', $preAcquisition, $investee)];
        $retranslatable = [];

        foreach ($acquisition['fair_value'] as $adjustment) {
            $scaled = ConsolidationMath::percentOf($adjustment['amount'], $acquiredPercentage);
            $line = $this->groupLine($adjustment['account_code'], $adjustment['account_name'], $adjustment['account_type'], $adjustment['account_type'] === 'liability' ? ConsolidationMath::neg($scaled) : $scaled, $investee);
            $extra[] = $line;
            $retranslatable[] = $line;
        }

        $deferredLine = $this->groupLine($group->syntheticCode('deferred_tax'), 'Deferred tax on fair-value adjustments', 'liability', ConsolidationMath::neg(ConsolidationMath::percentOf($deferredTax, $acquiredPercentage)), $investee);
        $extra[] = $deferredLine;
        $retranslatable[] = $deferredLine;

        if (ConsolidationMath::isPositive($goodwill) || ConsolidationMath::isZero($goodwill)) {
            $goodwillLine = $this->groupLine($group->syntheticCode('goodwill'), 'Goodwill', 'asset', $goodwill, $investee);
            $extra[] = $goodwillLine;
            $retranslatable[] = $goodwillLine;

            if (($acquisition['expected_future_charges'] ?? null) !== null) {
                $draft->warn('provision_ignored', "The expected future charges entered for {$draft->memberName($investee)} are ignored: there is a positive goodwill, not a negative difference (OIC 17 para. 73).", ['member_tenant_id' => $investee]);
            }
        } else {
            $this->negativeGoodwillLines($group, $draft, $investee, $acquisition, $goodwill, $yearStart, $periodEnd, $extra);
        }

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

        if ($group->goodwill_translation !== 'closing' || $acquisition['rate'] === '1.000000') {
            return [];
        }

        $closing = $this->translator->ratesFor($acquisition['input_currency'], strtoupper($group->group_currency), $yearStart, $periodEnd)['closing'];
        $factor = bcdiv($closing, $acquisition['rate'], 10);
        $retranslation = [];
        $total = ConsolidationMath::zero();

        foreach ($retranslatable as $line) {
            $lineNet = ConsolidationMath::sub($line['debit'], $line['credit']);
            $difference = ConsolidationMath::sub(ConsolidationMath::mul($lineNet, $factor), $lineNet);

            if (! ConsolidationMath::isZero($difference)) {
                $retranslation[] = $this->groupLine($line['account_code'], $line['account_name'], $line['account_type'], $difference, $investee);
                $total = ConsolidationMath::add($total, $difference);
            }
        }

        if ($retranslation !== []) {
            $retranslation[] = $this->groupLine($group->translation_reserve_code, 'Translation reserve', 'equity', ConsolidationMath::neg($total), $investee);
        }

        return $retranslation;
    }

    /**
     * The lines for a negative difference at the first consolidation. By default (OIC 17 para. 71-72) it is a
     * consolidation reserve in equity, except for the part that the user declares as expected unfavourable results,
     * which goes to a consolidation provision for future risks and charges among the liabilities (para. 73), up to the
     * negative difference; what remains after the provision is the reserve (para. 74). With the group option
     * `immediate_income` (the treatment of the IFRS 3 summary of ifrs.org, read on 2026-11-11: a bargain purchase goes
     * to profit or loss at once) the whole amount is income of the year of the acquisition, or retained earnings in
     * later years, and the provision does not apply; the reassessment that IFRS 3 asks before recognising the gain is
     * not performed.
     *
     * @param  array<string, mixed>  $acquisition
     * @param  list<array<string, mixed>>  $extra
     */
    private function negativeGoodwillLines(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, string $goodwill, CarbonImmutable $yearStart, CarbonImmutable $periodEnd, array &$extra): void
    {
        $charges = $acquisition['expected_future_charges'] ?? null;

        if ($group->negative_goodwill_treatment === 'immediate_income') {
            if ($charges !== null) {
                $draft->warn('provision_ignored', "The expected future charges entered for {$draft->memberName($investee)} are ignored: the group takes a bargain purchase to income at once.", ['member_tenant_id' => $investee]);
            }

            $date = CarbonImmutable::parse($acquisition['acquisition_date']);
            $extra[] = $date >= $yearStart && $date <= $periodEnd
                ? $this->groupLine($group->syntheticCode('bargain_purchase_gain'), 'Gain on a bargain purchase', 'revenue', $goodwill, $investee)
                : $this->groupLine($group->syntheticCode('bargain_purchase_prior'), 'Gain on a bargain purchase of earlier years', 'equity', $goodwill, $investee);

            return;
        }

        $negative = ConsolidationMath::abs($goodwill);
        $provision = $charges !== null && ConsolidationMath::isPositive($charges) ? ConsolidationMath::min($charges, $negative) : ConsolidationMath::zero();

        if (ConsolidationMath::isPositive($provision)) {
            $draft->provisions[$investee] = $provision;
            $extra[] = $this->groupLine($group->syntheticCode('consolidation_provision'), 'Consolidation provision for future risks and charges', 'liability', ConsolidationMath::neg($provision), $investee);
        }

        $reserve = ConsolidationMath::sub($negative, $provision);

        if (ConsolidationMath::isPositive($reserve)) {
            $extra[] = $this->groupLine($group->syntheticCode('negative_goodwill'), 'Consolidation reserve (negative goodwill)', 'equity', ConsolidationMath::neg($reserve), $investee);
        }
    }

    /**
     * Use of the consolidation provision (OIC 17 para. 73): straight-line by whole months over the years entered with
     * the expected charges, from the acquisition date and whether or not the losses occur; the use of the year is shown
     * on its own income account, the use of earlier years in retained earnings.
     *
     * @param  array<string, mixed>  $acquisition
     */
    private function provisionRelease(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $provision = $draft->provisions[$investee] ?? null;
        $years = (int) ($acquisition['provision_release_years'] ?? 0);

        if ($provision === null || $years < 1) {
            return;
        }

        $date = CarbonImmutable::parse($acquisition['acquisition_date']);
        $months = $years * 12;
        $priorMonths = $date < $yearStart ? min($months, $this->wholeMonths($date, $yearStart)) : 0;
        $currentStart = $date > $yearStart ? $date : $yearStart;
        $currentMonths = max(0, min($months - $priorMonths, $this->wholeMonths($currentStart, $periodEnd->addDay())));

        if ($priorMonths + $currentMonths === 0) {
            return;
        }

        $prior = ConsolidationMath::mul($provision, bcdiv((string) $priorMonths, (string) $months, 10));
        $current = ConsolidationMath::mul($provision, bcdiv((string) $currentMonths, (string) $months, 10));
        $total = ConsolidationMath::add($prior, $current);

        $this->adjust($draft, ConsolidationAdjustmentType::ProvisionRelease, null, $investee, "Use of the consolidation provision of {$draft->memberName($investee)} to {$periodEnd->toDateString()}", array_values(array_filter([
            $this->groupLine($group->syntheticCode('consolidation_provision'), 'Consolidation provision for future risks and charges', 'liability', $total, $investee),
            $this->groupLine($group->syntheticCode('provision_release_income'), 'Use of the consolidation provision', 'revenue', ConsolidationMath::neg($current), $investee),
            $this->groupLine($group->syntheticCode('provision_release_prior'), 'Use of the consolidation provision in earlier years', 'equity', ConsolidationMath::neg($prior), $investee),
        ], static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit']))));
    }

    /**
     * The share of every member that enters the consolidated accounts, as a percentage: the effective ownership for a
     * proportional member, 100 for any other (the same rule as a run), for the reports that must scale like it.
     *
     * @return array<string, string>
     *
     * @throws ConsolidationException
     */
    public function consolidationShares(ConsolidationGroup $group): array
    {
        $members = ConsolidationMember::withoutGlobalScopes()->where('group_id', $group->id)->where('tenant_id', $group->tenant_id)->get()->values();

        if ($members->isEmpty()) {
            return [];
        }

        $effective = $this->effectiveOwnership($members);
        $shares = [];

        foreach ($members as $member) {
            $shares[(string) $member->member_tenant_id] = $member->consolidation_method === ConsolidationMethod::Proportional ? $effective[(string) $member->member_tenant_id] : '100.0000';
        }

        return $shares;
    }

    /**
     * Release of the fair-value adjustments that have a useful life, to the period end (assumption 8b; OIC 17 para.
     * 75-77). The release is on the recognised amount, straight-line by whole months; the deferred tax recognised on
     * the adjustment unwinds with it; nothing reaches the minority interests.
     *
     * @param  array<string, mixed>  $acquisition
     */
    private function fairValueRelease(ConsolidationGroup $group, ConsolidationDraft $draft, string $investee, array $acquisition, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): void
    {
        $acquiredPercentage = $acquisition['acquired_percentage'] ?? $draft->members[$investee]['ownership_percentage'];
        $date = CarbonImmutable::parse($acquisition['acquisition_date']);
        $chargeStart = $date;

        if ($group->fair_value_first_year_rule === 'full_year') {
            $chargeStart = $yearStart;

            while ($chargeStart > $date) {
                $chargeStart = $chargeStart->subYearNoOverflow();
            }
        }

        $taxRate = $acquisition['recognise_deferred_tax'] ? $acquisition['deferred_tax_rate'] : null;

        foreach ($acquisition['fair_value'] as $adjustment) {
            $life = (int) ($adjustment['useful_life_years'] ?? 0);

            if ($life < 1) {
                continue;
            }

            $months = $life * 12;
            $priorMonths = $chargeStart < $yearStart ? min($months, $this->wholeMonths($chargeStart, $yearStart)) : 0;
            $currentStart = $chargeStart > $yearStart ? $chargeStart : $yearStart;
            $currentMonths = max(0, min($months - $priorMonths, $this->wholeMonths($currentStart, $periodEnd->addDay())));

            if ($priorMonths + $currentMonths === 0) {
                continue;
            }

            $recognised = ConsolidationMath::percentOf($adjustment['amount'], $acquiredPercentage);
            $prior = ConsolidationMath::mul($recognised, bcdiv((string) $priorMonths, (string) $months, 10));
            $current = ConsolidationMath::mul($recognised, bcdiv((string) $currentMonths, (string) $months, 10));
            $total = ConsolidationMath::add($prior, $current);
            $isLiability = $adjustment['account_type'] === 'liability';
            $signed = static fn (string $amount): string => $isLiability ? ConsolidationMath::neg($amount) : $amount;

            $lines = [
                $this->groupLine($adjustment['account_code'], $adjustment['account_name'], $adjustment['account_type'], $signed(ConsolidationMath::neg($total)), $investee),
                $this->groupLine($group->syntheticCode('fair_value_depreciation'), 'Release of fair-value adjustments', 'expense', $signed($current), $investee),
                $this->groupLine($group->syntheticCode('fair_value_prior'), 'Release of fair-value adjustments of earlier years', 'equity', $signed($prior), $investee),
            ];

            if ($taxRate !== null) {
                $taxTotal = ConsolidationMath::percentOf($total, $taxRate);
                $taxCurrent = ConsolidationMath::percentOf($current, $taxRate);
                $lines[] = $this->groupLine($group->syntheticCode('deferred_tax'), 'Deferred tax on fair-value adjustments', 'liability', $signed($taxTotal), $investee);
                $lines[] = $this->groupLine($group->syntheticCode('deferred_tax_income'), 'Deferred tax income (consolidation)', 'expense', $signed(ConsolidationMath::neg($taxCurrent)), $investee);
                $lines[] = $this->groupLine($group->syntheticCode('fair_value_prior'), 'Release of fair-value adjustments of earlier years', 'equity', $signed(ConsolidationMath::neg(ConsolidationMath::sub($taxTotal, $taxCurrent))), $investee);
            }

            $this->adjust($draft, ConsolidationAdjustmentType::FairValueRelease, null, $investee, "Release of the fair-value adjustment on {$adjustment['account_code']} of {$draft->memberName($investee)} to {$periodEnd->toDateString()}", array_values(array_filter(
                $lines,
                static fn (array $line): bool => ! ConsolidationMath::isZero($line['debit']) || ! ConsolidationMath::isZero($line['credit'])
            )));
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

            foreach ([$seller, $buyer] as $party) {
                $acquiredOn = $draft->acquisitions[$party]['acquisition_date'] ?? null;

                if ($acquiredOn !== null && $date < CarbonImmutable::parse($acquiredOn)) {
                    $draft->warn('transaction_before_consolidation', "An intragroup transaction dated {$date->toDateString()} precedes the acquisition of {$draft->memberName($party)}: it is not eliminated (OIC 17 para. 47).", ['transaction_id' => (string) $transaction->id]);

                    continue 2;
                }
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
                $unrealisedClose = ConsolidationMath::percentOf($margin, (string) $transaction->remaining_percentage);
                $unrealisedOpen = $priorYear ? ConsolidationMath::percentOf($margin, (string) ($transaction->opening_remaining_percentage ?? $transaction->remaining_percentage)) : ConsolidationMath::zero();
                $lines[] = $this->refLine($asset, $buyer, ConsolidationMath::neg($unrealisedClose));
                $lines[] = $this->groupLine($priorCode, $priorName, 'equity', $unrealisedOpen, null);
                $lines[] = $this->refLine($profit, $seller, ConsolidationMath::sub($unrealisedClose, $unrealisedOpen));
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
                $unrealisedClose = ConsolidationMath::sub($margin, $total);
                $unrealisedOpen = $priorYear ? $profitDebit : ConsolidationMath::zero();
                $lines[] = $priorYear
                    ? $this->groupLine($priorCode, $priorName, 'equity', $profitDebit, null)
                    : $this->refLine($profit, $seller, $profitDebit);
                $lines[] = $this->refLine($asset, $buyer, ConsolidationMath::neg($unrealisedClose));
                $lines[] = $this->refLine($depreciation, $buyer, ConsolidationMath::neg($current));
            }

            $deferredAsset = ConsolidationMath::zero();
            $taxProfitAndLoss = ConsolidationMath::zero();

            if ($group->deferred_tax_on_unrealised_profit) {
                $rate = $draft->members[$buyer]['tax_rate'] ?? ($group->unrealised_profit_tax_rate === null ? null : (string) $group->unrealised_profit_tax_rate);

                if ($rate === null) {
                    throw new ConsolidationException('Deferred tax on unrealised intragroup profit is on but there is no tax rate: set the tax rate of the buying member or the group rate. No rate is assumed.');
                }

                $deferredAsset = ConsolidationMath::percentOf($unrealisedClose, $rate);
                $taxPrior = ConsolidationMath::percentOf($unrealisedOpen, $rate);
                $taxProfitAndLoss = ConsolidationMath::sub($deferredAsset, $taxPrior);
                $lines[] = $this->groupLine($group->syntheticCode('deferred_tax_asset'), 'Deferred tax asset on unrealised intragroup profit', 'asset', $deferredAsset, $buyer);
                $lines[] = $this->groupLine($priorCode, $priorName, 'equity', ConsolidationMath::neg($taxPrior), null);
                $lines[] = $this->groupLine($group->syntheticCode('deferred_tax_income'), 'Deferred tax income on unrealised intragroup profit', 'expense', ConsolidationMath::neg($taxProfitAndLoss), $seller);
            }

            $upstream = $draft->upstream[$seller] ?? ['net_assets' => ConsolidationMath::zero(), 'profit' => ConsolidationMath::zero()];
            $draft->upstream[$seller] = [
                'net_assets' => ConsolidationMath::add($upstream['net_assets'], ConsolidationMath::sub($unrealisedClose, $deferredAsset)),
                'profit' => ConsolidationMath::add($upstream['profit'], ConsolidationMath::neg(ConsolidationMath::sub(ConsolidationMath::sub($unrealisedClose, $unrealisedOpen), $taxProfitAndLoss))),
            ];

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
            $upstream = $draft->upstream[$tenantId] ?? ['net_assets' => ConsolidationMath::zero(), 'profit' => ConsolidationMath::zero()];
            $netAssets = ConsolidationMath::sub(
                ConsolidationMath::sub($this->netAssets($draft, $tenantId), $draft->investmentsEliminated[$tenantId] ?? ConsolidationMath::zero()),
                $upstream['net_assets']
            );
            $profit = ConsolidationMath::add(ConsolidationMath::neg($this->incomeNet($draft, $tenantId)), $upstream['profit']);

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
            if ($member['holder_tenant_id'] !== null || $member['fiscal_year_offset_months'] !== 0 || $member['consolidation_method'] === 'proportional' || $member['tax_rate'] !== null || $member['consolidation_basis'] !== 'own_year') {
                $members[$tenantId] = [$member['holder_tenant_id'], $member['effective_ownership'], $member['fiscal_year_offset_months'], $member['year_start'], $member['consolidation_share'], ...($member['tax_rate'] !== null ? [$member['tax_rate']] : []), ...($member['consolidation_basis'] !== 'own_year' ? [$member['consolidation_basis']] : [])];
            }
        }

        $settings = [
            $group->elimination_difference_policy, $group->goodwill_method, $group->goodwill_amortisation_years, (bool) $group->require_account_mapping, $group->synthetic_accounts ?? [],
            (bool) $group->deferred_tax_on_unrealised_profit, $group->unrealised_profit_tax_rate === null ? null : (string) $group->unrealised_profit_tax_rate, $group->fair_value_first_year_rule, $group->goodwill_translation,
            ...($group->negative_goodwill_treatment !== 'consolidation_reserve' ? [$group->negative_goodwill_treatment] : []),
        ];

        if ($members !== []) {
            $extensions['members'] = $members;
        }

        if ($settings !== ['keep_difference', 'none', null, false, [], false, null, 'pro_rata', 'historical']) {
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
