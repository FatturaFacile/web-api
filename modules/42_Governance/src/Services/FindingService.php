<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Models\GovFinding;

/**
 * Life of a finding: open, in remediation while it has live actions, resolved when every action is verified, then
 * closed after a final check. A finding can also be accepted as a risk by management, which cancels the open actions.
 *
 * The remediation and resolved statuses follow the actions (see refreshRemediation); the other changes are requested
 * through transition(). The authority to accept a risk or to close a high or critical finding is checked by the policy.
 */
final readonly class FindingService
{
    public function __construct(private GovernanceNumbers $numbers, private GovernanceLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): GovFinding
    {
        if (isset($data['audit_id'])) {
            $audit = GovAudit::query()->findOrFail($data['audit_id']);
            if (! in_array($audit->status, GovAudit::FieldworkStatuses, true)) {
                throw ValidationException::withMessages(['audit_id' => [__('governance_errors.finding.audit_not_in_fieldwork', ['status' => $audit->status])]]);
            }
        }

        return DB::transaction(function () use ($tenantId, $data): GovFinding {
            $finding = GovFinding::create([
                'tenant_id' => $tenantId,
                'code' => $this->numbers->next('gov_findings', 'FND', $tenantId),
                'audit_id' => $data['audit_id'] ?? null,
                'control_id' => $data['control_id'] ?? null,
                'risk_id' => $data['risk_id'] ?? null,
                'source' => $data['source'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'severity' => $data['severity'],
                'recommendation' => $data['recommendation'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'status' => 'open',
            ]);

            $this->ledger->record('GOV_FINDING_RAISED', $finding, ['severity' => $finding->severity, 'source' => $finding->source]);

            return $finding;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GovFinding $finding, array $data): GovFinding
    {
        if (! $finding->isOpen()) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.finding.locked', ['status' => $finding->status])]]);
        }

        $finding->update(array_intersect_key($data, array_flip(['title', 'description', 'severity', 'recommendation', 'owner_id', 'risk_id', 'control_id'])));

        return $finding->refresh();
    }

    public function transition(GovFinding $finding, string $to, ?string $notes, ?string $actorId): GovFinding
    {
        return DB::transaction(function () use ($finding, $to, $notes, $actorId): GovFinding {
            $locked = GovFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovFinding::Transitions, $from, $to);

            $actions = GovAction::query()->where('finding_id', $locked->id)->get();
            $live = $actions->where('status', '!=', 'cancelled');
            $changes = ['status' => $to];

            switch ($to) {
                case 'remediation':
                    if ($live->isEmpty()) {
                        throw ValidationException::withMessages(['status' => [__('governance_errors.finding.action_required')]]);
                    }
                    $changes += ['closed_at' => null, 'closed_by' => null];

                    break;
                case 'resolved':
                    $this->assertActionsVerified($actions);

                    break;
                case 'risk_accepted':
                    $changes += [
                        'accepted_reason' => StatusMachine::requireNotes($notes, 'governance_errors.finding.acceptance_reason_required'),
                        'accepted_at' => now(),
                        'accepted_by' => $actorId,
                    ];
                    foreach ($actions->whereIn('status', ['open', 'in_progress', 'completed']) as $action) {
                        $action->update(['status' => 'cancelled', 'verification_notes' => 'risk_accepted']);
                    }

                    break;
                case 'closed':
                    if ($from === 'resolved') {
                        $this->assertActionsVerified($actions);
                    }
                    $changes += [
                        'closure_notes' => StatusMachine::requireNotes($notes, 'governance_errors.finding.closure_notes_required'),
                        'closed_at' => now(),
                        'closed_by' => $actorId,
                    ];

                    break;
                case 'open':
                    if ($actions->whereIn('status', ['open', 'in_progress', 'completed'])->isNotEmpty()) {
                        throw ValidationException::withMessages(['status' => [__('governance_errors.finding.live_actions')]]);
                    }
                    $changes += ['closed_at' => null, 'closed_by' => null, 'accepted_at' => null, 'accepted_by' => null, 'accepted_reason' => null];

                    break;
            }

            $locked->update($changes);
            $this->ledger->record('GOV_FINDING_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'severity' => $locked->severity, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * Aligns the status of a finding with its actions: the first live action puts it in remediation, the verification
     * of all of them resolves it. Called by the action service inside its transaction.
     */
    public function refreshRemediation(string $findingId): void
    {
        $finding = GovFinding::query()->whereKey($findingId)->lockForUpdate()->firstOrFail();
        $actions = GovAction::query()->where('finding_id', $findingId)->get();
        $live = $actions->where('status', '!=', 'cancelled');

        $target = match (true) {
            in_array($finding->status, ['closed', 'risk_accepted'], true) => null,
            $live->isEmpty() => $finding->status === 'remediation' ? 'open' : null,
            $live->every(static fn (GovAction $action): bool => $action->status === 'verified') => 'resolved',
            default => 'remediation',
        };

        if ($target !== null && $target !== $finding->status) {
            $from = $finding->status;
            $finding->update(['status' => $target]);
            $this->ledger->record('GOV_FINDING_TRANSITIONED', $finding, ['from' => $from, 'to' => $target, 'automatic' => true]);
        }
    }

    /**
     * @param  Collection<int, GovAction>  $actions
     */
    private function assertActionsVerified($actions): void
    {
        $live = $actions->where('status', '!=', 'cancelled');

        if ($live->isEmpty() || $live->contains(static fn (GovAction $action): bool => $action->status !== 'verified')) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.finding.actions_unverified', [
                'codes' => $live->filter(static fn (GovAction $action): bool => $action->status !== 'verified')->pluck('code')->implode(', ') ?: '-',
            ])]]);
        }
    }
}
