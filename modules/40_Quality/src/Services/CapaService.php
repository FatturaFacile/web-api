<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsNonconformity;

/**
 * Corrective and preventive actions: raised with an owner and a due date, started, completed, then checked for
 * effectiveness. A completed action is not finished until its effectiveness is verified: effective means verified,
 * not effective means ineffective, which blocks the closure of the non-conformity (a follow-up action is raised).
 */
final readonly class CapaService
{
    public function __construct(
        private QualityNumbers $numbers,
        private QualityLedger $ledger,
        private NonconformityService $nonconformities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): QmsCapaAction
    {
        return DB::transaction(function () use ($tenantId, $data): QmsCapaAction {
            $nonconformity = null;
            if (isset($data['nonconformity_id'])) {
                $nonconformity = QmsNonconformity::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($data['nonconformity_id']);
                if (! $nonconformity->isOpen()) {
                    throw ValidationException::withMessages(['nonconformity_id' => ["The non-conformity is {$nonconformity->status}: no new action can be raised."]]);
                }
            }

            $action = QmsCapaAction::create([
                'tenant_id' => $tenantId,
                'nonconformity_id' => $nonconformity?->id,
                'number' => $this->numbers->next('qms_capa_actions', 'CAPA', $tenantId),
                'type' => $data['type'] ?? 'corrective',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_id' => $data['owner_id'],
                'due_date' => $data['due_date'],
                'effectiveness_check_due_date' => $data['effectiveness_check_due_date'] ?? null,
                'status' => 'open',
            ]);

            if ($nonconformity !== null) {
                $this->nonconformities->markCapaInProgress($nonconformity);
            }

            $this->ledger->record('QMS_CAPA_RAISED', $action, ['type' => $action->type, 'due_date' => $action->due_date->toDateString()]);

            return $action;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsCapaAction $action, array $data): QmsCapaAction
    {
        if (! in_array($action->status, QmsCapaAction::OpenStatuses, true)) {
            throw ValidationException::withMessages(['status' => ["The action is {$action->status} and cannot be changed."]]);
        }

        $action->update(array_intersect_key($data, array_flip(['title', 'description', 'owner_id', 'due_date', 'effectiveness_check_due_date'])));

        return $action->refresh();
    }

    public function start(QmsCapaAction $action): QmsCapaAction
    {
        return $this->transition($action, ['open'], 'in_progress', 'QMS_CAPA_STARTED', []);
    }

    public function complete(QmsCapaAction $action, ?string $actorId, ?string $effectivenessCheckDueDate = null): QmsCapaAction
    {
        return $this->transition($action, ['open', 'in_progress'], 'completed', 'QMS_CAPA_COMPLETED', array_filter([
            'completed_at' => now(),
            'completed_by' => $this->uuidOrNull($actorId),
            'effectiveness_check_due_date' => $effectivenessCheckDueDate,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Records the effectiveness check of a completed action.
     */
    public function verifyEffectiveness(QmsCapaAction $action, string $result, ?string $notes, ?string $actorId): QmsCapaAction
    {
        return $this->transition($action, ['completed'], $result === 'effective' ? 'verified' : 'ineffective', 'QMS_CAPA_EFFECTIVENESS_CHECKED', [
            'effectiveness_result' => $result,
            'effectiveness_notes' => $notes,
            'effectiveness_checked_at' => now(),
            'effectiveness_checked_by' => $this->uuidOrNull($actorId),
        ]);
    }

    public function cancel(QmsCapaAction $action): QmsCapaAction
    {
        return $this->transition($action, ['open', 'in_progress', 'completed', 'ineffective'], 'cancelled', 'QMS_CAPA_CANCELLED', []);
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(QmsCapaAction $action, array $from, string $to, string $event, array $changes): QmsCapaAction
    {
        return DB::transaction(function () use ($action, $from, $to, $event, $changes): QmsCapaAction {
            $locked = QmsCapaAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ["The action is {$locked->status}: expected ".implode(' or ', $from).'.']]);
            }

            $locked->update($changes + ['status' => $to]);
            $this->ledger->record($event, $locked, ['to' => $to, 'result' => $locked->effectiveness_result]);

            return $locked->refresh();
        });
    }

    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
