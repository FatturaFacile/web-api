<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\DTOs\SubmitCSATDTO;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Records a satisfaction answer. When the ticket has a survey sent on resolution still waiting for an answer, that
 * survey is completed instead of adding another one, so the CSAT endpoint and the survey link feed the same row.
 */
final readonly class SubmitCSATAction
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function execute(SubmitCSATDTO $dto, ?string $actorUserId = null): CSATSurvey
    {
        return DB::transaction(function () use ($dto): CSATSurvey {
            $pending = $dto->ticketId === null ? null : CSATSurvey::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('ticket_id', $dto->ticketId)
                ->where('status', CSATSurvey::StatusPending)
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                return $this->complete($pending, $dto->score, $dto->feedback, $dto->respondentEmail);
            }

            $ticket = $dto->ticketId === null ? null : Ticket::query()->where('tenant_id', $dto->tenantId)->find($dto->ticketId);

            $survey = CSATSurvey::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'ticket_id' => $dto->ticketId,
                'work_order_id' => $dto->workOrderId,
                'customer_id' => $dto->customerId ?: $ticket?->customer_id,
                'respondent_email' => $dto->respondentEmail,
                'score' => $dto->score,
                'sentiment' => CSATSurvey::sentimentFor($dto->score),
                'feedback' => $dto->feedback,
                'status' => CSATSurvey::StatusCompleted,
                'responded_at' => now(),
            ]);

            $ticket?->forceFill(['csat_score' => $dto->score])->save();

            return $this->recorded($survey);
        });
    }

    /**
     * Completes a survey waiting for an answer: once, and only before its link expires.
     */
    public function complete(CSATSurvey $survey, int $score, ?string $feedback, ?string $respondentEmail = null): CSATSurvey
    {
        return DB::transaction(function () use ($survey, $score, $feedback, $respondentEmail): CSATSurvey {
            $survey = CSATSurvey::query()->whereKey($survey->id)->lockForUpdate()->firstOrFail();

            if ($survey->status !== CSATSurvey::StatusPending) {
                throw new HelpdeskRuleViolation('This survey was already answered.');
            }

            if ($survey->expires_at !== null && $survey->expires_at->isPast()) {
                throw new HelpdeskRuleViolation('This survey has expired.');
            }

            $survey->fill([
                'score' => $score,
                'sentiment' => CSATSurvey::sentimentFor($score),
                'feedback' => $feedback,
                'respondent_email' => $respondentEmail ?? $survey->respondent_email,
                'status' => CSATSurvey::StatusCompleted,
                'responded_at' => now(),
            ])->save();

            if ($survey->ticket_id !== null) {
                Ticket::query()->whereKey($survey->ticket_id)->update(['csat_score' => $score]);
            }

            return $this->recorded($survey);
        });
    }

    private function recorded(CSATSurvey $survey): CSATSurvey
    {
        $this->auditLedger->record(
            'CSAT_SUBMITTED',
            $survey,
            null,
            ['score' => $survey->score],
            'MODULE_17_ESM'
        );

        $this->dispatcher->dispatch(new CustomerSatisfactionUpdatedEvent(
            csatSurveyId: $survey->id,
            score: (int) $survey->score,
            feedback: $survey->feedback,
            ticketId: $survey->ticket_id,
            workOrderId: $survey->work_order_id,
            tenantId: $survey->tenant_id,
            companyId: $survey->company_id ?? $survey->tenant_id
        ));

        return $survey;
    }
}
