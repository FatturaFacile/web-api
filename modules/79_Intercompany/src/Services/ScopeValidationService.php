<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationTranslator;
use Modules\GeneralLedger\Services\Consolidation\EntityBalanceReader;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Support\GroupScope;

/**
 * Step one of the guided consolidation: scope and tree validation before a run is created. It checks, without writing
 * anything, that the group has a parent and members, that every holder is in the group, that ownership percentages are
 * valid, that every foreign entity has a closing rate (fixed for the period or published) and that every entity has a
 * ledger balance to consolidate (trial balance readiness).
 */
final readonly class ScopeValidationService
{
    public function __construct(
        private GroupScope $scope,
        private ConsolidationTranslator $translator,
        private EntityBalanceReader $reader,
    ) {}

    /**
     * @return array{ready: bool, checks: list<array{code: string, passed: bool, entity_tenant_id: ?string, detail: ?string}>}
     */
    public function validate(User $user, ConsolidationGroup $group, string $fiscalPeriodId): array
    {
        $this->scope->assertReadable($user, $group);
        $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->find($fiscalPeriodId);

        if ($period === null) {
            throw new IntercompanyException('The fiscal period must belong to the parent entity of the group.', 'period_invalid');
        }

        $year = FiscalYear::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->findOrFail($period->fiscal_year_id);
        $yearStart = CarbonImmutable::parse($year->start_date);
        $periodEnd = CarbonImmutable::parse($period->end_date);
        $groupCurrency = strtoupper((string) $group->group_currency);
        $checks = [];

        $members = ConsolidationMember::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('group_id', $group->id)->get();
        $ids = $members->pluck('member_tenant_id')->map(static fn (mixed $id): string => (string) $id)->all();

        $checks[] = $this->check('has_parent', $members->contains(fn (ConsolidationMember $member): bool => (bool) $member->is_parent));
        $checks[] = $this->check('has_members', $members->count() > 1);

        foreach ($members as $member) {
            $tenantId = (string) $member->member_tenant_id;
            $percentage = (string) $member->ownership_percentage;
            $holder = $member->holder_tenant_id === null ? null : (string) $member->holder_tenant_id;

            $checks[] = $this->check('ownership_valid', $member->is_parent || (ConsolidationMath::isPositive($percentage) && ConsolidationMath::cmp($percentage, '100') <= 0), $tenantId, $percentage);
            $checks[] = $this->check('holder_in_group', $holder === null || in_array($holder, $ids, true), $tenantId, $holder);

            $currency = TenantAccountingCurrency::of($tenantId);
            $checks[] = $this->check('currency_defined', $currency !== '', $tenantId, $currency);

            try {
                $this->translator->ratesFor($currency, $groupCurrency, $yearStart, $periodEnd);
                $rateOk = true;
                $rateDetail = null;
            } catch (ConsolidationException $exception) {
                $rateOk = false;
                $rateDetail = $exception->getMessage();
            }

            $checks[] = $this->check('rate_available', $rateOk, $tenantId, $rateDetail ?? $currency.'/'.$groupCurrency);
            $checks[] = $this->check('trial_balance_ready', count($this->reader->read($tenantId, $yearStart, $periodEnd)) > 0, $tenantId, null);
        }

        return ['ready' => collect($checks)->every(static fn (array $check): bool => $check['passed']), 'checks' => $checks];
    }

    /**
     * @return array{code: string, passed: bool, entity_tenant_id: ?string, detail: ?string}
     */
    private function check(string $code, bool $passed, ?string $entity = null, ?string $detail = null): array
    {
        return ['code' => $code, 'passed' => $passed, 'entity_tenant_id' => $entity, 'detail' => $detail];
    }
}
