<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovObligation;

/**
 * The compliance obligations calendar. An open obligation is overdue after its due date. Completing a recurring
 * obligation records the fulfilment and moves it to the next period, so the calendar always shows the next deadline.
 */
final readonly class ObligationService
{
    public function __construct(private GovernanceLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): GovObligation
    {
        return DB::transaction(function () use ($tenantId, $data): GovObligation {
            $obligation = GovObligation::create([
                'tenant_id' => $tenantId,
                'template_key' => null,
                'framework' => $data['framework'],
                'jurisdiction' => $data['jurisdiction'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'legal_reference' => $data['legal_reference'] ?? null,
                'recurrence' => $data['recurrence'],
                'due_date' => $data['due_date'],
                'reminder_days' => $data['reminder_days'] ?? 30,
                'owner_id' => $data['owner_id'] ?? null,
                'evidence_document_id' => $data['evidence_document_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            $this->ledger->record('GOV_OBLIGATION_CREATED', $obligation, ['framework' => $obligation->framework, 'due_date' => $obligation->due_date->toDateString()]);

            return $obligation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GovObligation $obligation, array $data): GovObligation
    {
        if (! in_array($obligation->status, GovObligation::OpenStatuses, true)) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.obligation.locked', ['status' => $obligation->status])]]);
        }

        $obligation->update(array_intersect_key($data, array_flip(['framework', 'jurisdiction', 'title', 'description', 'legal_reference', 'recurrence', 'due_date', 'reminder_days', 'owner_id', 'evidence_document_id', 'notes'])));

        return $obligation->refresh();
    }

    public function transition(GovObligation $obligation, string $to, ?string $actorId): GovObligation
    {
        return DB::transaction(function () use ($obligation, $to, $actorId): GovObligation {
            $locked = GovObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovObligation::Transitions, $from, $to);

            $locked->update(['status' => $to]);
            $this->ledger->record('GOV_OBLIGATION_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * Records the fulfilment. A one-off obligation becomes completed; a recurring one moves to the first due date after
     * the completion, counted in whole periods from its current due date, and goes back to pending.
     *
     * @param  array<string, mixed>  $data
     */
    public function complete(GovObligation $obligation, array $data, ?string $actorId): GovObligation
    {
        return DB::transaction(function () use ($obligation, $data, $actorId): GovObligation {
            $locked = GovObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, GovObligation::OpenStatuses, true)) {
                throw ValidationException::withMessages(['status' => [__('governance_errors.obligation.not_open', ['status' => $locked->status])]]);
            }

            $completedOn = Carbon::parse((string) ($data['completed_on'] ?? now()->toDateString()))->startOfDay();
            $changes = [
                'last_completed_at' => $completedOn->toDateString(),
                'last_completed_by' => $actorId,
                'evidence_document_id' => $data['evidence_document_id'] ?? $locked->evidence_document_id,
                'notes' => $data['notes'] ?? $locked->notes,
            ];

            if ($locked->recurrence === 'once') {
                $changes['status'] = 'completed';
            } else {
                $changes['status'] = 'pending';
                $changes['due_date'] = $this->nextDueDate($locked->due_date, $locked->recurrence, $completedOn)->toDateString();
            }

            $locked->update($changes);
            $this->ledger->record('GOV_OBLIGATION_COMPLETED', $locked, ['recurrence' => $locked->recurrence, 'next_due_date' => $locked->status === 'pending' ? $locked->due_date->toDateString() : null, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * Creates the obligations of the templates of the chosen frameworks that the company does not have yet.
     *
     * @param  list<string>  $frameworks
     * @return array{created: int, skipped: int}
     */
    public function seedTemplates(string $tenantId, array $frameworks, ?string $firstDueDate, ?string $ownerId): array
    {
        $dueDate = $firstDueDate ?? Carbon::today()->addDays(30)->toDateString();

        return DB::transaction(function () use ($tenantId, $frameworks, $dueDate, $ownerId): array {
            $existing = GovObligation::query()->whereNotNull('template_key')->pluck('template_key')->all();
            $created = 0;
            $skipped = 0;

            foreach (ComplianceTemplates::forFrameworks($frameworks) as $template) {
                if (in_array($template['key'], $existing, true)) {
                    $skipped++;

                    continue;
                }

                $obligation = GovObligation::create([
                    'tenant_id' => $tenantId,
                    'template_key' => $template['key'],
                    'framework' => $template['framework'],
                    'jurisdiction' => $template['jurisdiction'],
                    'title' => $template['title'],
                    'description' => $template['description'],
                    'legal_reference' => $template['legal_reference'],
                    'recurrence' => $template['recurrence'],
                    'due_date' => $dueDate,
                    'reminder_days' => $template['reminder_days'],
                    'owner_id' => $ownerId,
                    'status' => 'pending',
                ]);
                $created++;

                $this->ledger->record('GOV_OBLIGATION_SEEDED', $obligation, ['template_key' => $template['key']]);
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * First due date after the completion: the current one plus one period, repeated while it is not after the date of
     * the completion (an obligation fulfilled very late skips the periods already gone).
     */
    private function nextDueDate(Carbon $dueDate, string $recurrence, Carbon $completedOn): Carbon
    {
        $months = GovObligation::PeriodMonths[$recurrence];
        $next = $dueDate->copy()->addMonthsNoOverflow($months);

        while ($next->lte($completedOn)) {
            $next = $next->addMonthsNoOverflow($months);
        }

        return $next;
    }
}
