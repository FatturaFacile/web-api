<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\IntercompanyAccountMapping;
use Modules\GeneralLedger\Models\IntercompanyRelation;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Configuration of the consolidation: groups, members with their ownership and method, intercompany relations and the
 * mapping of intercompany accounts. Everything belongs to the tenant that owns the group (the parent entity). A
 * tenant can become a member, and its accounts can be mapped, only by a user who can read its ledger
 * (ConsolidationAccessGuard): nobody can pull an entity he cannot see into a group. The parent entity is a member of
 * its own group, fully consolidated at 100 %, and cannot be changed or removed.
 *
 * What the configuration means for the figures is in ConsolidationCalculator.
 */
final readonly class ConsolidationGroupService
{
    public function __construct(private ConsolidationAccessGuard $guard) {}

    /**
     * @param  array{name: string, group_currency: string, notes?: ?string, translation_reserve_code?: ?string, consolidation_difference_code?: ?string, equity_method_result_code?: ?string, equity_method_reserve_code?: ?string}  $data
     */
    public function createGroup(string $ownerTenantId, array $data): ConsolidationGroup
    {
        $this->assertSettings($data, null);

        return DB::transaction(function () use ($ownerTenantId, $data): ConsolidationGroup {
            $group = ConsolidationGroup::create([
                ...array_filter($data, static fn (mixed $value): bool => $value !== null),
                'tenant_id' => $ownerTenantId,
                'group_currency' => strtoupper($data['group_currency']),
            ]);

            ConsolidationMember::create([
                'tenant_id' => $ownerTenantId,
                'group_id' => $group->id,
                'member_tenant_id' => $ownerTenantId,
                'ownership_percentage' => '100',
                'consolidation_method' => ConsolidationMethod::Full->value,
                'is_parent' => true,
            ]);

            return $group->load('members');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(ConsolidationGroup $group, array $data): ConsolidationGroup
    {
        $this->assertSettings($data, $group);

        if (isset($data['group_currency'])) {
            $data['group_currency'] = strtoupper((string) $data['group_currency']);
        }

        $group->update(array_filter($data, static fn (mixed $value): bool => $value !== null));

        return $group->load('members');
    }

    /**
     * The synthetic account roles must be known and a straight-line goodwill method needs its number of years.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ConsolidationException
     */
    private function assertSettings(array $data, ?ConsolidationGroup $group): void
    {
        foreach (array_keys($data['synthetic_accounts'] ?? []) as $role) {
            if (! array_key_exists((string) $role, ConsolidationGroup::SyntheticDefaults)) {
                throw new ConsolidationException("Unknown synthetic account role [{$role}]: use one of ".implode(', ', array_keys(ConsolidationGroup::SyntheticDefaults)).'.');
            }
        }

        $method = $data['goodwill_method'] ?? $group->goodwill_method ?? 'none';
        $years = $data['goodwill_amortisation_years'] ?? $group?->goodwill_amortisation_years;

        if ($method === 'straight_line' && ($years === null || (int) $years < 1)) {
            throw new ConsolidationException('The straight-line goodwill method needs goodwill_amortisation_years: the period is an input of the group and none is assumed.');
        }
    }

    /**
     * @throws ConflictHttpException when the group has runs: they are the record of what was consolidated
     */
    public function deleteGroup(ConsolidationGroup $group): void
    {
        if ($group->runs()->exists()) {
            throw new ConflictHttpException('A group with consolidation runs cannot be deleted: delete its runs first.');
        }

        DB::transaction(function () use ($group): void {
            $group->relations()->get()->each(static function (IntercompanyRelation $relation): void {
                $relation->mappings()->get()->each->forceDelete();
                $relation->forceDelete();
            });
            $group->members()->get()->each->forceDelete();
            $group->delete();
        });
    }

    /**
     * @throws AccessDeniedHttpException when the user cannot read the ledger of the entity
     * @throws ConflictHttpException when the entity is already a member
     */
    public function addMember(User $user, ConsolidationGroup $group, string $memberTenantId, string $ownershipPercentage, ConsolidationMethod $method, ?string $holderTenantId = null, int $fiscalYearOffsetMonths = 0, ?string $taxRate = null, string $consolidationBasis = 'own_year'): ConsolidationMember
    {
        if (! Tenant::withoutGlobalScopes()->whereKey($memberTenantId)->exists() || ! $this->guard->canReadEntity($user, $memberTenantId)) {
            throw new AccessDeniedHttpException('You cannot add an entity whose ledger you cannot read.');
        }

        if ($group->members()->where('member_tenant_id', $memberTenantId)->exists()) {
            throw new ConflictHttpException('The entity is already a member of the group.');
        }

        $this->assertHolderAllowed($group, $memberTenantId, $holderTenantId);
        $this->assertBasis($consolidationBasis, $fiscalYearOffsetMonths);

        return ConsolidationMember::create([
            'tenant_id' => $group->tenant_id,
            'group_id' => $group->id,
            'member_tenant_id' => $memberTenantId,
            'ownership_percentage' => $ownershipPercentage,
            'consolidation_method' => $method->value,
            'is_parent' => false,
            'holder_tenant_id' => $holderTenantId,
            'fiscal_year_offset_months' => $fiscalYearOffsetMonths,
            'tax_rate' => $taxRate,
            'consolidation_basis' => $consolidationBasis,
        ]);
    }

    /**
     * The interim statement basis reads twelve months ending at the consolidation date and ignores the fiscal year
     * offset, so the two cannot be combined.
     *
     * @throws ConsolidationException
     */
    private function assertBasis(string $basis, int $offsetMonths): void
    {
        if ($basis === 'interim_twelve_months' && $offsetMonths !== 0) {
            throw new ConsolidationException('A member read from an interim statement of twelve months has no fiscal year offset: set the offset to 0.');
        }
    }

    /**
     * The holder of a member must be a member of the same group, the parent or fully consolidated, and the chain of
     * holders must not come back to the member (indirect holdings, ConsolidationCalculator assumption 4).
     *
     * @throws ConsolidationException
     */
    private function assertHolderAllowed(ConsolidationGroup $group, string $memberTenantId, ?string $holderTenantId): void
    {
        if ($holderTenantId === null) {
            return;
        }

        $members = $group->members()->get()->keyBy(static fn (ConsolidationMember $member): string => (string) $member->member_tenant_id);
        $holder = $members->get($holderTenantId);

        if ($holder === null) {
            throw new ConsolidationException('The holder must be a member of the group.');
        }

        if ($holderTenantId === $memberTenantId) {
            throw new ConsolidationException('An entity cannot hold itself.');
        }

        if (! $holder->is_parent && $holder->consolidation_method !== ConsolidationMethod::Full) {
            throw new ConsolidationException('An entity can be held only by the parent or by a fully consolidated member.');
        }

        $current = $holder;
        $seen = [];

        while ($current !== null && ! $current->is_parent && ! in_array((string) $current->member_tenant_id, $seen, true)) {
            if ((string) $current->holder_tenant_id === $memberTenantId) {
                throw new ConsolidationException('The holdings of the group would form a cycle.');
            }

            $seen[] = (string) $current->member_tenant_id;
            $current = $current->holder_tenant_id === null ? null : $members->get((string) $current->holder_tenant_id);
        }
    }

    /**
     * @throws ConsolidationException for the parent entity
     */
    public function updateMember(ConsolidationMember $member, string $ownershipPercentage, ConsolidationMethod $method, ?string $holderTenantId = null, ?int $fiscalYearOffsetMonths = null, bool $changeHolder = false, ?string $taxRate = null, bool $changeTaxRate = false, ?string $consolidationBasis = null): ConsolidationMember
    {
        if ($member->is_parent) {
            throw new ConsolidationException('The parent entity is always fully consolidated at 100 %.');
        }

        $changes = ['ownership_percentage' => $ownershipPercentage, 'consolidation_method' => $method->value];

        if ($changeHolder) {
            $this->assertHolderAllowed($member->group()->firstOrFail(), (string) $member->member_tenant_id, $holderTenantId);
            $changes['holder_tenant_id'] = $holderTenantId;
        }

        if ($method !== ConsolidationMethod::Full && ConsolidationMember::query()->where('group_id', $member->group_id)->where('holder_tenant_id', $member->member_tenant_id)->exists()) {
            throw new ConsolidationException('A member that holds other members must stay fully consolidated.');
        }

        if ($fiscalYearOffsetMonths !== null) {
            $changes['fiscal_year_offset_months'] = $fiscalYearOffsetMonths;
        }

        if ($changeTaxRate) {
            $changes['tax_rate'] = $taxRate;
        }

        $basis = $consolidationBasis ?? $member->consolidation_basis;
        $this->assertBasis($basis, $fiscalYearOffsetMonths ?? (int) $member->fiscal_year_offset_months);

        if ($consolidationBasis !== null) {
            $changes['consolidation_basis'] = $consolidationBasis;
        }

        $member->update($changes);

        return $member;
    }

    /**
     * @throws ConsolidationException for the parent entity
     * @throws ConflictHttpException when an intercompany relation uses the entity
     */
    public function removeMember(ConsolidationMember $member): void
    {
        if ($member->is_parent) {
            throw new ConsolidationException('The parent entity cannot be removed from its group.');
        }

        if (ConsolidationMember::query()->where('group_id', $member->group_id)->where('holder_tenant_id', $member->member_tenant_id)->exists()) {
            throw new ConflictHttpException('The entity holds other members: change their holder first.');
        }

        $used = IntercompanyRelation::query()
            ->where('group_id', $member->group_id)
            ->where(static fn ($query) => $query->where('entity_a_tenant_id', $member->member_tenant_id)->orWhere('entity_b_tenant_id', $member->member_tenant_id))
            ->exists();

        if ($used) {
            throw new ConflictHttpException('The entity has intercompany relations: delete them first.');
        }

        $member->forceDelete();
    }

    /**
     * @throws ConsolidationException when an entity is not a member or both are the same
     * @throws ConflictHttpException when the pair already has a relation
     */
    public function createRelation(User $user, ConsolidationGroup $group, string $entityA, string $entityB, string $tolerance, ?string $description): IntercompanyRelation
    {
        if ($entityA === $entityB) {
            throw new ConsolidationException('An intercompany relation needs two different entities.');
        }

        foreach ([$entityA, $entityB] as $entity) {
            if (! $group->members()->where('member_tenant_id', $entity)->exists()) {
                throw new ConsolidationException('Both entities of an intercompany relation must be members of the group.');
            }
        }

        $this->guard->assertCanReadEntities($user, [$entityA, $entityB]);

        $exists = IntercompanyRelation::query()->where('group_id', $group->id)->where(static fn ($query) => $query
            ->where(static fn ($pair) => $pair->where('entity_a_tenant_id', $entityA)->where('entity_b_tenant_id', $entityB))
            ->orWhere(static fn ($pair) => $pair->where('entity_a_tenant_id', $entityB)->where('entity_b_tenant_id', $entityA)))->exists();

        if ($exists) {
            throw new ConflictHttpException('The two entities already have an intercompany relation.');
        }

        return IntercompanyRelation::create([
            'tenant_id' => $group->tenant_id,
            'group_id' => $group->id,
            'entity_a_tenant_id' => $entityA,
            'entity_b_tenant_id' => $entityB,
            'tolerance_amount' => $tolerance,
            'description' => $description,
        ]);
    }

    public function deleteRelation(IntercompanyRelation $relation): void
    {
        DB::transaction(function () use ($relation): void {
            $relation->mappings()->get()->each->forceDelete();
            $relation->forceDelete();
        });
    }

    /**
     * Maps an account of entity A to an account of entity B. Both must be accounts of their own entity and of the type
     * the mapping needs; the account B of an investment may be left out only for an investee on the equity method.
     *
     * @throws ConsolidationException when an account is not of the entity or does not fit the type
     */
    public function addMapping(User $user, IntercompanyRelation $relation, IntercompanyMappingType $type, string $accountA, ?string $accountB): IntercompanyAccountMapping
    {
        $this->guard->assertCanReadEntities($user, [(string) $relation->entity_a_tenant_id, (string) $relation->entity_b_tenant_id]);

        $a = $this->accountOf((string) $relation->entity_a_tenant_id, $accountA);
        $b = $accountB === null ? null : $this->accountOf((string) $relation->entity_b_tenant_id, $accountB);

        if ($type === IntercompanyMappingType::InvestmentEquity) {
            $investee = ConsolidationMember::query()->where('group_id', $relation->group_id)->where('member_tenant_id', $relation->entity_b_tenant_id)->first();

            if ($a->account_type !== 'asset' || ($b !== null && $b->account_type !== 'equity') || ($b === null && $investee?->consolidation_method !== ConsolidationMethod::Equity)) {
                throw new ConsolidationException('An investment mapping pairs an asset account of the investor with an equity account of the investee (the equity account may be left out only for an entity on the equity method).');
            }
        } else {
            if ($b === null) {
                throw new ConsolidationException('The account of the second entity is required.');
            }

            $types = [$a->account_type, $b->account_type];
            $valid = $type === IntercompanyMappingType::ReceivablePayable
                ? in_array('asset', $types, true) && in_array('liability', $types, true)
                : $this->isRevenueCostPair($types);

            if (! $valid) {
                throw new ConsolidationException($type === IntercompanyMappingType::ReceivablePayable
                    ? 'A receivable/payable mapping pairs an asset account with a liability account.'
                    : 'A revenue/cost mapping pairs a revenue account with a cost of goods sold or expense account.');
            }
        }

        $duplicate = IntercompanyAccountMapping::query()
            ->where('relation_id', $relation->id)
            ->where('mapping_type', $type->value)
            ->where('account_a_id', $accountA)
            ->when($accountB === null, static fn ($query) => $query->whereNull('account_b_id'), static fn ($query) => $query->where('account_b_id', $accountB))
            ->exists();

        if ($duplicate) {
            throw new ConflictHttpException('The mapping already exists.');
        }

        return IntercompanyAccountMapping::create([
            'tenant_id' => $relation->tenant_id,
            'relation_id' => $relation->id,
            'mapping_type' => $type->value,
            'account_a_id' => $accountA,
            'account_b_id' => $accountB,
        ]);
    }

    public function deleteMapping(IntercompanyAccountMapping $mapping): void
    {
        $mapping->forceDelete();
    }

    /**
     * @param  list<string>  $types
     */
    private function isRevenueCostPair(array $types): bool
    {
        return in_array('revenue', $types, true) && (in_array('cogs', $types, true) || in_array('expense', $types, true));
    }

    private function accountOf(string $tenantId, string $accountId): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($accountId);

        if ($account === null) {
            throw new ConsolidationException('An account of the mapping does not belong to the entity it is mapped for.');
        }

        return $account;
    }
}
