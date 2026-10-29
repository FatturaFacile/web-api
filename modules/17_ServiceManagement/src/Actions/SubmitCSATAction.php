<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\DTOs\SubmitCSATDTO;
use Modules\ServiceManagement\Events\CustomerSatisfactionUpdatedEvent;
use Modules\ServiceManagement\Models\CSATSurvey;

final readonly class SubmitCSATAction
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function execute(SubmitCSATDTO $dto, ?string $actorUserId = null): CSATSurvey
    {
        return DB::transaction(function () use ($dto) {
            $survey = CSATSurvey::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'ticket_id' => $dto->ticketId,
                'work_order_id' => $dto->workOrderId,
                'customer_id' => $dto->customerId,
                'respondent_email' => $dto->respondentEmail,
                'score' => $dto->score,
                'feedback' => $dto->feedback,
                'status' => 'completed',
                'responded_at' => now(),
            ]);

            $this->auditLedger->record(
                'CSAT_SUBMITTED',
                $survey,
                null,
                ['score' => $dto->score],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new CustomerSatisfactionUpdatedEvent(
                csatSurveyId: $survey->id,
                score: $survey->score,
                feedback: $survey->feedback,
                ticketId: $survey->ticket_id,
                workOrderId: $survey->work_order_id,
                tenantId: $survey->tenant_id,
                companyId: $survey->company_id ?? $survey->tenant_id
            ));

            return $survey;
        });
    }
}
