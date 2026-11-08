<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Enums\ContractStatus;
use Modules\Contracts\Models\ContractAlert;

/**
 * Daily pass over the contracts in force of a tenant: raises an alert before the notice deadline and before the end,
 * renews what renews by itself, expires what ended without renewal and reminds the owners of obligations that are due.
 * Notice already given stops both the notice alert and the renewal. A contract locked by a workflow is left to that
 * workflow.
 */
final readonly class ContractDeadlineService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ContractRegister $register,
        private ContractLifecycle $lifecycle,
        private ContractObligationService $obligations,
    ) {}

    /**
     * @return array{alerts: int, renewed: int, expired: int, obligation_reminders: int}
     */
    public function run(string $tenantId, CarbonImmutable $today): array
    {
        $alerts = 0;
        $renewed = 0;
        $expired = 0;

        foreach (ContractKind::cases() as $kind) {
            $contracts = $kind->modelClass()::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Renewal->value])
                ->whereNotNull($kind->endColumn())
                ->get();

            foreach ($contracts as $contract) {
                $alerts += $this->raiseAlerts($kind, $contract, $today);

                if ($this->renewIfDue($kind, $contract, $today)) {
                    $renewed++;
                } elseif ($this->expireIfEnded($kind, $contract, $today)) {
                    $expired++;
                }
            }
        }

        return ['alerts' => $alerts, 'renewed' => $renewed, 'expired' => $expired, 'obligation_reminders' => $this->obligations->remindDue($tenantId, $today)];
    }

    private function raiseAlerts(ContractKind $kind, Model $contract, CarbonImmutable $today): int
    {
        $alertDays = (int) $contract->getAttribute('alert_days_before');
        $end = CarbonImmutable::parse($contract->getAttribute($kind->endColumn()));
        $notice = $contract->getAttribute('notice_deadline');
        $raised = 0;

        if ($notice !== null && $contract->getAttribute('notice_given_at') === null) {
            $raised += $this->raiseWithin($kind, $contract, 'notice_deadline', CarbonImmutable::parse($notice), $alertDays, $today);
        }

        return $raised + $this->raiseWithin($kind, $contract, 'expiry', $end, $alertDays, $today);
    }

    private function raiseWithin(ContractKind $kind, Model $contract, string $type, CarbonImmutable $due, int $alertDays, CarbonImmutable $today): int
    {
        if ($today->gt($due) || $today->lt($due->subDays($alertDays))) {
            return 0;
        }

        $alert = ContractAlert::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $contract->getAttribute('tenant_id'),
                'contract_kind' => $kind->value,
                'contract_id' => $contract->getKey(),
                'alert_type' => $type,
                'due_on' => $due->toDateString(),
            ],
            ['status' => 'open']
        );

        return $alert->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * A contract past its end date that did not renew lapses: it becomes `expired`, so nobody keeps treating it as in
     * force. A contract held by a workflow is left to that workflow.
     */
    private function expireIfEnded(ContractKind $kind, Model $contract, CarbonImmutable $today): bool
    {
        if ($contract->getAttribute('is_locked') || CarbonImmutable::parse($contract->getAttribute($kind->endColumn()))->gte($today)) {
            return false;
        }

        $this->lifecycle->transition($kind, $contract, ContractStatus::Expired, 'The end date passed without a renewal.', null, $today);

        return true;
    }

    private function renewIfDue(ContractKind $kind, Model $contract, CarbonImmutable $today): bool
    {
        $isDue = $contract->getAttribute('auto_renew')
            && ! empty($contract->getAttribute('renewal_term_months'))
            && $contract->getAttribute('notice_given_at') === null
            && ! $contract->getAttribute('is_locked')
            && CarbonImmutable::parse($contract->getAttribute($kind->endColumn()))->lt($today);

        if (! $isDue) {
            return false;
        }

        $this->register->renew($kind, $contract, null, 'automatic', null);

        return true;
    }
}
