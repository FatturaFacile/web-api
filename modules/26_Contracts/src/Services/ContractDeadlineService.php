<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Models\ContractAlert;

/**
 * Daily pass over the active contracts of a tenant: raises an alert before the notice deadline and before the end, and
 * renews what renews by itself. Notice already given stops both the notice alert and the renewal. A contract locked by
 * a workflow is left to that workflow.
 */
final readonly class ContractDeadlineService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ContractRegister $register) {}

    /**
     * @return array{alerts: int, renewed: int}
     */
    public function run(string $tenantId, CarbonImmutable $today): array
    {
        $alerts = 0;
        $renewed = 0;

        foreach (ContractKind::cases() as $kind) {
            $contracts = $kind->modelClass()::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->whereNotNull($kind->endColumn())
                ->get();

            foreach ($contracts as $contract) {
                $alerts += $this->raiseAlerts($kind, $contract, $today);
                $renewed += $this->renewIfDue($kind, $contract, $today) ? 1 : 0;
            }
        }

        return ['alerts' => $alerts, 'renewed' => $renewed];
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
