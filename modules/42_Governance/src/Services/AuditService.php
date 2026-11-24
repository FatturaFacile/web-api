<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Models\GovAuditPlan;
use Modules\Governance\Models\GovFinding;

/**
 * The internal audit plan of the year and its audits.
 *
 * A plan is approved only with at least one audit and closed when every audit is closed or cancelled. An audit starts
 * only under an approved plan, and closes with a conclusion once no high or critical finding is left untreated.
 */
final readonly class AuditService
{
    public function __construct(private GovernanceNumbers $numbers, private GovernanceLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(string $tenantId, array $data): GovAuditPlan
    {
        return DB::transaction(function () use ($tenantId, $data): GovAuditPlan {
            $plan = GovAuditPlan::create([
                'tenant_id' => $tenantId,
                'year' => $data['year'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
            ]);

            $this->ledger->record('GOV_AUDIT_PLAN_CREATED', $plan, ['year' => $plan->year]);

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePlan(GovAuditPlan $plan, array $data): GovAuditPlan
    {
        if ($plan->status === 'closed') {
            throw ValidationException::withMessages(['status' => [__('governance_errors.plan.closed')]]);
        }

        if (isset($data['year']) && (int) $data['year'] !== $plan->year && $plan->audits()->exists()) {
            throw ValidationException::withMessages(['year' => [__('governance_errors.plan.year_locked')]]);
        }

        $plan->update(array_intersect_key($data, array_flip(['year', 'title', 'description'])));

        return $plan->refresh();
    }

    public function transitionPlan(GovAuditPlan $plan, string $to, ?string $actorId): GovAuditPlan
    {
        return DB::transaction(function () use ($plan, $to, $actorId): GovAuditPlan {
            $locked = GovAuditPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovAuditPlan::Transitions, $from, $to);

            $audits = GovAudit::query()->where('audit_plan_id', $locked->id)->get();

            if ($to === 'approved') {
                if ($audits->where('status', '!=', 'cancelled')->isEmpty()) {
                    throw ValidationException::withMessages(['status' => [__('governance_errors.plan.audit_required')]]);
                }
                $locked->update(['status' => $to, 'approved_at' => now(), 'approved_by' => $actorId]);
            } else {
                $unfinished = $audits->whereNotIn('status', ['closed', 'cancelled']);
                if ($unfinished->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => [__('governance_errors.plan.audits_unfinished', ['codes' => $unfinished->pluck('code')->implode(', ')])]]);
                }
                $locked->update(['status' => $to, 'closed_at' => now()]);
            }

            $this->ledger->record('GOV_AUDIT_PLAN_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAudit(string $tenantId, array $data): GovAudit
    {
        $this->assertPlanAcceptsAudit($data['audit_plan_id'] ?? null, (string) $data['planned_start']);

        return DB::transaction(function () use ($tenantId, $data): GovAudit {
            $audit = GovAudit::create([
                'tenant_id' => $tenantId,
                'audit_plan_id' => $data['audit_plan_id'] ?? null,
                'code' => $this->numbers->next('gov_audits', 'AUD', $tenantId),
                'title' => $data['title'],
                'audit_type' => $data['audit_type'],
                'scope' => $data['scope'],
                'objectives' => $data['objectives'] ?? null,
                'lead_auditor_id' => $data['lead_auditor_id'],
                'team_member_ids' => $this->team($data['team_member_ids'] ?? [], (string) $data['lead_auditor_id']),
                'planned_start' => $data['planned_start'],
                'planned_end' => $data['planned_end'],
                'status' => 'planned',
            ]);

            $this->ledger->record('GOV_AUDIT_PLANNED', $audit, ['audit_plan_id' => $audit->audit_plan_id, 'planned_start' => $audit->planned_start->toDateString()]);

            return $audit;
        });
    }

    /**
     * A planned audit can be changed freely; once it started only the team and the conclusion can change.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAudit(GovAudit $audit, array $data): GovAudit
    {
        if (in_array($audit->status, ['closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.audit.locked', ['status' => $audit->status])]]);
        }

        if ($audit->status !== 'planned' && array_diff(array_keys($data), ['team_member_ids', 'conclusion']) !== []) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.audit.only_team_and_conclusion', ['status' => $audit->status])]]);
        }

        $merged = $data + ['planned_start' => $audit->planned_start->toDateString(), 'planned_end' => $audit->planned_end->toDateString()];

        if ($audit->status === 'planned') {
            if (Carbon::parse((string) $merged['planned_end'])->lt(Carbon::parse((string) $merged['planned_start']))) {
                throw ValidationException::withMessages(['planned_end' => [__('governance_errors.audit.dates_inverted')]]);
            }
            if (array_key_exists('audit_plan_id', $data) || isset($data['planned_start'])) {
                $this->assertPlanAcceptsAudit($data['audit_plan_id'] ?? $audit->audit_plan_id, (string) $merged['planned_start']);
            }
        }

        $fields = array_intersect_key($data, array_flip(['audit_plan_id', 'title', 'audit_type', 'scope', 'objectives', 'lead_auditor_id', 'planned_start', 'planned_end', 'conclusion']));

        if (array_key_exists('team_member_ids', $data) || isset($data['lead_auditor_id'])) {
            $fields['team_member_ids'] = $this->team($data['team_member_ids'] ?? $audit->team_member_ids ?? [], (string) ($data['lead_auditor_id'] ?? $audit->lead_auditor_id));
        }

        $audit->update($fields);

        return $audit->refresh();
    }

    public function transitionAudit(GovAudit $audit, string $to, ?string $notes, ?string $actorId): GovAudit
    {
        return DB::transaction(function () use ($audit, $to, $notes, $actorId): GovAudit {
            $locked = GovAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovAudit::Transitions, $from, $to);

            $changes = ['status' => $to];

            switch ($to) {
                case 'in_progress':
                    if ($from === 'planned') {
                        $plan = $locked->audit_plan_id === null ? null : GovAuditPlan::query()->find($locked->audit_plan_id);
                        if ($plan !== null && $plan->status !== 'approved') {
                            throw ValidationException::withMessages(['status' => [__('governance_errors.audit.plan_not_approved', ['status' => $plan->status])]]);
                        }
                        $changes['actual_start'] = now()->toDateString();
                    }

                    break;
                case 'closed':
                    $conclusion = StatusMachine::requireNotes($notes ?? $locked->conclusion, 'governance_errors.audit.conclusion_required');
                    $untreated = GovFinding::query()->where('audit_id', $locked->id)->where('status', 'open')->whereIn('severity', ['high', 'critical'])->pluck('code');
                    if ($untreated->isNotEmpty()) {
                        throw ValidationException::withMessages(['status' => [__('governance_errors.audit.untreated_findings', ['codes' => $untreated->implode(', ')])]]);
                    }
                    $changes += ['conclusion' => $conclusion, 'actual_end' => now()->toDateString()];

                    break;
                case 'cancelled':
                    $changes['conclusion'] = StatusMachine::requireNotes($notes, 'governance_errors.audit.cancellation_reason_required');

                    break;
            }

            $locked->update($changes);
            $this->ledger->record('GOV_AUDIT_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'actor_id' => $actorId]);

            return $locked->refresh();
        });
    }

    /**
     * A closed plan takes no audits, and the audit must start in the year of the plan.
     */
    private function assertPlanAcceptsAudit(?string $planId, string $plannedStart): void
    {
        if ($planId === null) {
            return;
        }

        $plan = GovAuditPlan::query()->findOrFail($planId);

        if ($plan->status === 'closed') {
            throw ValidationException::withMessages(['audit_plan_id' => [__('governance_errors.plan.closed')]]);
        }

        if ((int) Carbon::parse($plannedStart)->format('Y') !== $plan->year) {
            throw ValidationException::withMessages(['planned_start' => [__('governance_errors.audit.outside_plan_year', ['year' => $plan->year])]]);
        }
    }

    /**
     * The team without duplicates and without the lead auditor.
     *
     * @param  list<string>  $memberIds
     * @return list<string>
     */
    private function team(array $memberIds, string $leadAuditorId): array
    {
        return array_values(array_unique(array_filter($memberIds, static fn (string $id): bool => $id !== $leadAuditorId)));
    }
}
