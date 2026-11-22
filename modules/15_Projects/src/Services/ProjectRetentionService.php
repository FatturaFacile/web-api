<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Events\ProjectRetentionReleasedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectBillingEvent;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectRetentionLedgerEntry;
use Modules\Projects\Services\Fiscal\ProjectFiscalBillingStrategyResolver;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Guarantee retention (ritenuta a garanzia, Garantierückbehalt) of a billing contract (Mod 081): the amounts withheld by
 * SAL certificates and milestone invoices, and their release in full or in part once the contractual rule is met.
 *
 *  manual              the manager decides
 *  on_acceptance       the customer accepted the work (collaudo / Abnahme): the request must say so
 *  on_warranty_expiry  the warranty period ended: the release date must have been reached
 *  on_milestone        the named milestone of the project is completed
 *
 * A release is a settlement of the amount withheld at payment, not a new sale, so it creates a settled billing event and
 * publishes an event for receivables and treasury instead of drafting an invoice.
 */
final readonly class ProjectRetentionService
{
    public function __construct(
        private ProjectFiscalBillingStrategyResolver $strategies,
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @return array{withheld: string, released: string, balance: string, release_rule: string, release_date: string|null}
     */
    public function balance(ProjectBillingContract $contract): array
    {
        $entries = ProjectRetentionLedgerEntry::query()->where('billing_contract_id', $contract->id);
        $withheld = (string) (clone $entries)->where('entry_type', ProjectRetentionLedgerEntry::Withheld)->sum('amount');
        $released = (string) (clone $entries)->where('entry_type', ProjectRetentionLedgerEntry::Released)->sum('amount');

        return [
            'withheld' => Money::amount($withheld),
            'released' => Money::amount($released),
            'balance' => Money::amount(Money::sub($withheld, $released)),
            'release_rule' => $contract->retention_release_rule,
            'release_date' => $contract->retention_release_date?->toDateString(),
        ];
    }

    /**
     * Withholds the retention on an invoiced milestone amount.
     */
    public function withholdOnMilestone(Project $project, ProjectBillingContract $contract, ProjectMilestone $milestone, SalesInvoice $invoice, string $actorUserId): ?ProjectRetentionLedgerEntry
    {
        $percent = $this->strategies->forContract($contract)->effectiveRetentionPercent($contract);
        $amount = Money::amount(Money::mul($invoice->total_net, Money::div($percent, 100)));

        if (! Money::isPositive($amount)) {
            return null;
        }

        return ProjectRetentionLedgerEntry::create([
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'billing_contract_id' => $contract->id,
            'entry_type' => ProjectRetentionLedgerEntry::Withheld,
            'amount' => $amount,
            'entry_date' => now()->toDateString(),
            'reason' => "Milestone {$milestone->name}",
            'created_by' => $actorUserId,
        ]);
    }

    /**
     * Releases the retention, in full when no amount is given.
     *
     * @throws ConflictHttpException when the contractual release rule is not met
     * @throws ValidationException when the amount is not positive or exceeds the retention held
     */
    public function release(Project $project, ProjectBillingContract $contract, ?string $amount, string $releaseDate, bool $acceptanceConfirmed, ?string $milestoneId, ?string $reason, string $actorUserId): ProjectRetentionLedgerEntry
    {
        return DB::transaction(function () use ($project, $contract, $amount, $releaseDate, $acceptanceConfirmed, $milestoneId, $reason, $actorUserId): ProjectRetentionLedgerEntry {
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertRuleMet($project, $contract, $releaseDate, $acceptanceConfirmed, $milestoneId);

            $held = $this->balance($contract)['balance'];
            $release = $amount === null ? $held : Money::amount($amount);

            if (! Money::isPositive($release) || Money::compare($release, $held) > 0) {
                throw ValidationException::withMessages(['amount' => [__('project_billing.errors.retention_amount_invalid', ['balance' => $held])]]);
            }

            $billingEvent = ProjectBillingEvent::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'event_type' => 'retention_release',
                'source_reference_id' => $contract->id,
                'billing_amount' => $release,
                'status' => 'settled',
            ]);

            $entry = ProjectRetentionLedgerEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'billing_contract_id' => $contract->id,
                'entry_type' => ProjectRetentionLedgerEntry::Released,
                'amount' => $release,
                'entry_date' => $releaseDate,
                'reason' => $reason ?? $contract->retention_release_rule,
                'billing_event_id' => $billingEvent->id,
                'created_by' => $actorUserId,
            ]);

            $remaining = Money::amount(Money::sub($held, $release));
            $this->audit->record($project, $actorUserId, 'retention_released', "Retention released {$release}, {$remaining} still held", [], ['released' => $release, 'remaining' => $remaining], $entry);

            $this->dispatcher->publishAndDispatch(new ProjectRetentionReleasedEvent(
                projectId: (string) $project->id,
                billingContractId: (string) $contract->id,
                releasedAmount: $release,
                remainingRetention: $remaining,
                releaseDate: $releaseDate,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id),
            ));

            return $entry;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertRuleMet(Project $project, ProjectBillingContract $contract, string $releaseDate, bool $acceptanceConfirmed, ?string $milestoneId): void
    {
        $rule = $contract->retention_release_rule;

        if ($rule === 'on_acceptance' && ! $acceptanceConfirmed) {
            throw new ConflictHttpException(__('project_billing.errors.retention_acceptance_required'));
        }

        if ($rule === 'on_warranty_expiry' && ($contract->retention_release_date === null || $releaseDate < $contract->retention_release_date->toDateString())) {
            throw new ConflictHttpException(__('project_billing.errors.retention_warranty_not_expired', ['date' => $contract->retention_release_date?->toDateString() ?? '-']));
        }

        if ($rule === 'on_milestone') {
            $done = $milestoneId !== null && ProjectMilestone::query()->where('project_id', $project->id)->whereKey($milestoneId)->where('is_completed', true)->exists();

            if (! $done) {
                throw new ConflictHttpException(__('project_billing.errors.retention_milestone_not_completed'));
            }
        }
    }
}
