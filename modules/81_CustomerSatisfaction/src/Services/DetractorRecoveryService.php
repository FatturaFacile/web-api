<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\Contact;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryPriority;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Events\DetractorRecoveryOpened;
use Modules\CustomerSatisfaction\Exceptions\SatisfactionException;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Notifications\DetractorRecoveryNotification;

/**
 * Closed-loop detractor recovery: a detractor answer (NPS <= 6, CSAT <= 2, CES >= 5 or the threshold of the template) opens a case with
 * a 24 hour SLA, alerts the account manager and the support lead, creates the mandatory CRM follow-up task and raises the churn
 * risk of the customer. The case then follows a short status workflow that must end with a root cause and a resolution.
 */
final class DetractorRecoveryService
{
    public function __construct(
        private readonly CrmFollowUpGateway $crm,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly TeamDirectory $team,
    ) {}

    public function open(SurveyResponse $response, SurveyTemplate $template): DetractorRecoveryCase
    {
        $existing = DetractorRecoveryCase::query()->where('survey_response_id', $response->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $priority = $this->priorityOf($response);
        $dueAt = now()->addHours($template->sla_hours > 0 ? $template->sla_hours : (int) config('customer_satisfaction.default_sla_hours'));
        $account = $this->crm->accountOf($response->customer_id);
        $accountManagerId = $account?->owner_user_id;
        $supportLeadId = $this->supportLeadId();

        $case = DB::transaction(function () use ($response, $priority, $dueAt, $account, $accountManagerId, $supportLeadId): DetractorRecoveryCase {
            $churn = $this->crm->raiseChurnRisk($account, $priority === RecoveryPriority::Urgent ? 'critical' : 'high');

            $customer = Contact::query()->find($response->customer_id);
            $taskId = $this->crm->createFollowUpTask(
                $account,
                $response->customer_id,
                __('customer_satisfaction.crm_task_subject', ['customer' => (string) ($customer?->company_name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')))]),
                $priority->value,
                $dueAt,
                $accountManagerId ?? $supportLeadId,
                ['source' => 'customer_satisfaction', 'survey_response_id' => $response->id, 'score' => $response->score],
            );

            return DetractorRecoveryCase::query()->create([
                'survey_response_id' => $response->id,
                'customer_id' => $response->customer_id,
                'assigned_account_manager_id' => $accountManagerId,
                'assigned_support_lead_id' => $supportLeadId,
                'crm_task_id' => $taskId,
                'priority' => $priority->value,
                'status' => RecoveryStatus::Open->value,
                'sla_due_at' => $dueAt,
                'churn_risk_updated' => $churn['changed'],
                'previous_churn_risk' => $churn['previous'],
                'new_churn_risk' => $churn['new'],
            ]);
        });

        $this->auditLedger->record('DETRACTOR_RECOVERY_OPENED', $case, null, ['score' => $response->score, 'priority' => $priority->value, 'sla_due_at' => $dueAt->toIso8601String()], 'MODULE_245_CSAT');

        $event = new DetractorRecoveryOpened((string) $case->id, (string) $response->id, $response->customer_id, $priority->value, $dueAt->toIso8601String(), $accountManagerId, $supportLeadId);
        $recipients = User::query()->whereIn('id', array_values(array_unique(array_filter([$accountManagerId, $supportLeadId]))))->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new DetractorRecoveryNotification($event));
        }

        Event::dispatch($event);

        return $case;
    }

    /**
     * Moves a case through the workflow. Closing or resolving needs the root cause and the resolution; the first contact is stamped
     * when the work starts, and the CRM follow-up task is completed with the case.
     */
    public function transition(DetractorRecoveryCase $case, RecoveryStatus $to, ?string $rootCauseCode = null, ?string $resolutionSummary = null): DetractorRecoveryCase
    {
        if ($case->status === $to) {
            return $case;
        }

        if (! in_array($to, $case->status->next(), true)) {
            throw new SatisfactionException('invalid_status_transition', 409, ['from' => $case->status->value, 'to' => $to->value]);
        }

        $rootCause = $rootCauseCode ?? $case->root_cause_code;
        $summary = $resolutionSummary ?? $case->resolution_summary;

        if ($to->isFinal() && (blank($rootCause) || blank($summary))) {
            throw new SatisfactionException('resolution_requires_root_cause', 422);
        }

        $before = ['status' => $case->status->value];

        $case->fill([
            'status' => $to->value,
            'root_cause_code' => $rootCause,
            'resolution_summary' => $summary,
            'first_contacted_at' => $case->first_contacted_at ?? ($to === RecoveryStatus::Open ? null : now()),
            'resolved_at' => $to->isFinal() ? ($case->resolved_at ?? now()) : null,
        ])->save();

        if ($to->isFinal()) {
            $this->crm->completeTask($case->crm_task_id);
        }

        $this->auditLedger->record('DETRACTOR_RECOVERY_'.strtoupper($to->value), $case, $before, ['status' => $to->value, 'root_cause_code' => $rootCause], 'MODULE_245_CSAT');

        return $case;
    }

    public function assign(DetractorRecoveryCase $case, ?string $accountManagerId, ?string $supportLeadId): DetractorRecoveryCase
    {
        $case->forceFill([
            'assigned_account_manager_id' => $accountManagerId,
            'assigned_support_lead_id' => $supportLeadId,
        ])->save();

        return $case;
    }

    /**
     * Urgent when the answer is at the worst end of the scale (NPS 0-3, CSAT 1, CES 7), high otherwise.
     */
    private function priorityOf(SurveyResponse $response): RecoveryPriority
    {
        $urgent = match ($response->survey_type) {
            SurveyType::Nps => $response->score <= 3,
            SurveyType::Csat => $response->score <= 1,
            SurveyType::Ces => $response->score >= 7,
        };

        return $urgent ? RecoveryPriority::Urgent : RecoveryPriority::High;
    }

    private function supportLeadId(): ?string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId === null ? null : $this->team->supportLeadId((string) $tenantId);
    }
}
