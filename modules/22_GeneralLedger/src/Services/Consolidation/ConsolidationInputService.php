<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationAccountMapping;
use Modules\GeneralLedger\Models\ConsolidationAcquisition;
use Modules\GeneralLedger\Models\ConsolidationFairValueAdjustment;
use Modules\GeneralLedger\Models\ConsolidationGoodwillImpairment;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\IntercompanyRelation;
use Modules\GeneralLedger\Models\IntragroupDividend;
use Modules\GeneralLedger\Models\IntragroupTransaction;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The inputs that the consolidation takes from the user instead of from the ledgers: the mapping of the accounts of the
 * members to the group chart, the acquisition records with fair-value adjustments and goodwill impairments, the
 * intragroup transactions with an unrealised margin and the intragroup dividends. Every figure is in group currency, and
 * how each one is used is in ConsolidationCalculator. An entity can be named, and its accounts used, only by a user who
 * can read its ledger (ConsolidationAccessGuard), and only when it is a member of the group.
 */
final readonly class ConsolidationInputService
{
    public function __construct(private ConsolidationAccessGuard $guard) {}

    /**
     * @throws ConsolidationException
     */
    public function addAccountMapping(User $user, ConsolidationGroup $group, string $memberTenantId, string $accountId, string $groupAccountCode, ?string $groupAccountName): ConsolidationAccountMapping
    {
        $this->member($user, $group, $memberTenantId);
        $this->account($memberTenantId, $accountId);

        if (ConsolidationAccountMapping::query()->where('group_id', $group->id)->where('member_tenant_id', $memberTenantId)->where('account_id', $accountId)->exists()) {
            throw new ConflictHttpException('The account is already mapped.');
        }

        return ConsolidationAccountMapping::create([
            'tenant_id' => $group->tenant_id,
            'group_id' => $group->id,
            'member_tenant_id' => $memberTenantId,
            'account_id' => $accountId,
            'group_account_code' => $groupAccountCode,
            'group_account_name' => $groupAccountName,
        ]);
    }

    /**
     * @param  array{member_tenant_id: string, acquisition_date: string, cost?: numeric-string|float|int|null, net_assets_at_acquisition: numeric-string|float|int, acquired_percentage?: numeric-string|float|int|null, recognise_deferred_tax?: bool, deferred_tax_rate?: numeric-string|float|int|null, input_currency?: ?string, fair_value_adjustments?: list<array{account_code: string, account_name?: ?string, account_type: string, amount: numeric-string|float|int, useful_life_years?: ?int}>}  $data
     *
     * @throws ConsolidationException
     */
    public function addAcquisition(User $user, ConsolidationGroup $group, array $data): ConsolidationAcquisition
    {
        $member = $this->member($user, $group, $data['member_tenant_id']);

        if ($member->is_parent) {
            throw new ConsolidationException('The parent entity is not acquired within the group.');
        }

        if (($data['recognise_deferred_tax'] ?? false) && ! isset($data['deferred_tax_rate'])) {
            throw new ConsolidationException('Deferred tax on the fair-value adjustments needs its rate: the rate is an input and none is assumed.');
        }

        if (ConsolidationAcquisition::query()->where('group_id', $group->id)->where('member_tenant_id', $data['member_tenant_id'])->exists()) {
            throw new ConflictHttpException('The member already has an acquisition record: delete it first.');
        }

        return DB::transaction(function () use ($group, $data): ConsolidationAcquisition {
            $acquisition = ConsolidationAcquisition::create([
                'tenant_id' => $group->tenant_id,
                'group_id' => $group->id,
                'member_tenant_id' => $data['member_tenant_id'],
                'acquisition_date' => $data['acquisition_date'],
                'cost' => $data['cost'] ?? null,
                'net_assets_at_acquisition' => $data['net_assets_at_acquisition'],
                'acquired_percentage' => $data['acquired_percentage'] ?? null,
                'recognise_deferred_tax' => (bool) ($data['recognise_deferred_tax'] ?? false),
                'deferred_tax_rate' => $data['deferred_tax_rate'] ?? null,
                'input_currency' => isset($data['input_currency']) ? strtoupper((string) $data['input_currency']) : null,
            ]);

            foreach ($data['fair_value_adjustments'] ?? [] as $adjustment) {
                ConsolidationFairValueAdjustment::create([
                    'tenant_id' => $group->tenant_id,
                    'acquisition_id' => $acquisition->id,
                    'account_code' => $adjustment['account_code'],
                    'account_name' => $adjustment['account_name'] ?? null,
                    'account_type' => $adjustment['account_type'],
                    'amount' => $adjustment['amount'],
                    'useful_life_years' => $adjustment['useful_life_years'] ?? null,
                ]);
            }

            return $acquisition->load(['fairValueAdjustments', 'impairments']);
        });
    }

    /**
     * @throws ConflictHttpException when the year already has an impairment
     */
    public function addImpairment(ConsolidationAcquisition $acquisition, int $fiscalYear, string $amount): ConsolidationAcquisition
    {
        if (ConsolidationGoodwillImpairment::query()->where('acquisition_id', $acquisition->id)->where('fiscal_year', $fiscalYear)->exists()) {
            throw new ConflictHttpException('The goodwill impairment of that fiscal year is already entered: delete the acquisition record to change it.');
        }

        ConsolidationGoodwillImpairment::create(['tenant_id' => $acquisition->tenant_id, 'acquisition_id' => $acquisition->id, 'fiscal_year' => $fiscalYear, 'amount' => $amount]);

        return $acquisition->load(['fairValueAdjustments', 'impairments']);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConsolidationException
     */
    public function addTransaction(User $user, ConsolidationGroup $group, array $data): IntragroupTransaction
    {
        [$seller, $buyer] = [(string) $data['seller_tenant_id'], (string) $data['buyer_tenant_id']];

        if ($seller === $buyer) {
            throw new ConsolidationException('Seller and buyer must be different entities.');
        }

        $this->member($user, $group, $seller);
        $this->member($user, $group, $buyer);

        if (isset($data['relation_id'])) {
            $relation = IntercompanyRelation::query()->where('group_id', $group->id)->find($data['relation_id']);
            $pair = $relation === null ? [] : [(string) $relation->entity_a_tenant_id, (string) $relation->entity_b_tenant_id];

            if ($relation === null || ! in_array($seller, $pair, true) || ! in_array($buyer, $pair, true)) {
                throw new ConsolidationException('The intercompany relation must be one of the group between the seller and the buyer.');
            }
        }

        if (! isset($data['margin_amount']) && (! isset($data['sale_price']) || ! isset($data['seller_cost']))) {
            throw new ConsolidationException('Give the unrealised margin, or the sale price and the cost of the seller.');
        }

        $asset = $this->account($buyer, (string) $data['asset_account_id']);
        $profit = $this->account($seller, (string) $data['profit_account_id']);

        if ($asset->account_type !== 'asset' || ! in_array($profit->account_type, ['revenue', 'cogs', 'expense'], true)) {
            throw new ConsolidationException('The asset account of the buyer must be an asset and the profit account of the seller an income account.');
        }

        if ($data['asset_type'] === 'fixed_asset') {
            if (empty($data['useful_life_years']) || empty($data['depreciation_account_id'])) {
                throw new ConsolidationException('A fixed asset needs its useful life in years and the depreciation account of the buyer.');
            }

            if (! in_array($this->account($buyer, (string) $data['depreciation_account_id'])->account_type, ['expense', 'cogs'], true)) {
                throw new ConsolidationException('The depreciation account must be an expense account of the buyer.');
            }
        }

        return IntragroupTransaction::create([...$data, 'tenant_id' => $group->tenant_id, 'group_id' => $group->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConsolidationException
     */
    public function addDividend(User $user, ConsolidationGroup $group, array $data): IntragroupDividend
    {
        [$payer, $receiver] = [(string) $data['payer_tenant_id'], (string) $data['receiver_tenant_id']];

        if ($payer === $receiver) {
            throw new ConsolidationException('Payer and receiver must be different entities.');
        }

        $this->member($user, $group, $payer);
        $this->member($user, $group, $receiver);

        if ($this->account($receiver, (string) $data['income_account_id'])->account_type !== 'revenue' || $this->account($payer, (string) $data['equity_account_id'])->account_type !== 'equity') {
            throw new ConsolidationException('The dividend income account of the receiver must be a revenue account and the account of the payer an equity account.');
        }

        return IntragroupDividend::create([...$data, 'tenant_id' => $group->tenant_id, 'group_id' => $group->id]);
    }

    public function deleteAcquisition(ConsolidationAcquisition $acquisition): void
    {
        DB::transaction(function () use ($acquisition): void {
            $acquisition->fairValueAdjustments()->get()->each->forceDelete();
            $acquisition->impairments()->get()->each->forceDelete();
            $acquisition->forceDelete();
        });
    }

    private function member(User $user, ConsolidationGroup $group, string $tenantId): ConsolidationMember
    {
        $member = ConsolidationMember::query()->where('group_id', $group->id)->where('member_tenant_id', $tenantId)->first();

        if ($member === null) {
            throw new ConsolidationException('The entity must be a member of the group.');
        }

        $this->guard->assertCanReadEntities($user, [$tenantId]);

        return $member;
    }

    private function account(string $tenantId, string $accountId): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($accountId);

        if ($account === null) {
            throw new ConsolidationException('An account does not belong to the entity it is entered for.');
        }

        return $account;
    }
}
