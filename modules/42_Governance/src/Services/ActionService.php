<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovFinding;

/**
 * Remediation actions of a finding: raised with an owner and a due date that respects the term of the severity of the
 * finding, started, completed by the owner and verified by somebody else (segregation of duties). Every change realigns
 * the status of the finding.
 */
final readonly class ActionService
{
    public function __construct(
        private GovernanceNumbers $numbers,
        private GovernanceLedger $ledger,
        private FindingService $findings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(GovFinding $finding, array $data): GovAction
    {
        return DB::transaction(function () use ($finding, $data): GovAction {
            $locked = GovFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['finding_id' => [__('governance_errors.action.finding_closed', ['status' => $locked->status])]]);
            }

            $this->assertTermAllowed($locked, (string) $data['due_date']);

            $action = GovAction::create([
                'tenant_id' => $locked->tenant_id,
                'finding_id' => $locked->id,
                'code' => $this->numbers->next('gov_actions', 'ACT', $locked->tenant_id),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'],
                'due_date' => $data['due_date'],
                'status' => 'open',
            ]);

            $this->findings->refreshRemediation($locked->id);
            $this->ledger->record('GOV_ACTION_RAISED', $action, ['finding_id' => $locked->id, 'due_date' => $action->due_date->toDateString()]);

            return $action;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GovAction $action, array $data): GovAction
    {
        if (! in_array($action->status, GovAction::OpenStatuses, true)) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.action.locked', ['status' => $action->status])]]);
        }

        if (isset($data['due_date'])) {
            $this->assertTermAllowed(GovFinding::query()->findOrFail($action->finding_id), (string) $data['due_date']);
        }

        $action->update(array_intersect_key($data, array_flip(['title', 'description', 'owner_id', 'due_date'])));

        return $action->refresh();
    }

    public function transition(GovAction $action, string $to, ?string $notes, ?string $actorId): GovAction
    {
        return DB::transaction(function () use ($action, $to, $notes, $actorId): GovAction {
            $locked = GovAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovAction::Transitions, $from, $to);

            $changes = ['status' => $to];

            switch ($to) {
                case 'completed':
                    $changes += ['completed_at' => now(), 'completed_by' => $actorId, 'completion_notes' => $notes];

                    break;
                case 'verified':
                    if ($actorId !== null && $locked->completed_by === $actorId) {
                        throw ValidationException::withMessages(['status' => [__('governance_errors.action.segregation_of_duties')]]);
                    }
                    $changes += ['verified_at' => now(), 'verified_by' => $actorId, 'verification_notes' => $notes];

                    break;
                case 'in_progress':
                    if ($from === 'completed') {
                        $changes += [
                            'verification_notes' => StatusMachine::requireNotes($notes, 'governance_errors.action.rejection_notes_required'),
                            'completed_at' => null,
                            'completed_by' => null,
                        ];
                    }

                    break;
                case 'cancelled':
                    $changes['verification_notes'] = StatusMachine::requireNotes($notes, 'governance_errors.action.cancellation_notes_required');

                    break;
            }

            $locked->update($changes);
            $this->findings->refreshRemediation($locked->finding_id);
            $this->ledger->record('GOV_ACTION_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'finding_id' => $locked->finding_id, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * The due date of an action may not be later than the term of the severity of its finding (critical 30 days, high 90,
     * medium 180, low 365).
     */
    private function assertTermAllowed(GovFinding $finding, string $dueDate): void
    {
        $limit = Carbon::today()->addDays(GovAction::MaxDaysBySeverity[$finding->severity]);

        if (Carbon::parse($dueDate)->startOfDay()->gt($limit)) {
            throw ValidationException::withMessages(['due_date' => [__('governance_errors.action.term_too_long', [
                'severity' => $finding->severity,
                'days' => GovAction::MaxDaysBySeverity[$finding->severity],
                'limit' => $limit->toDateString(),
            ])]]);
        }
    }
}
