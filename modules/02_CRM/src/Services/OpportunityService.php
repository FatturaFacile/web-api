<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\CreateOpportunityDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Events\OpportunityBoardUpdated;
use Modules\CRM\Events\OpportunityCreatedEvent;
use Modules\CRM\Events\OpportunityLostEvent;
use Modules\CRM\Events\OpportunityStageChangedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\CRM\Events\ProjectOpportunityCreatedEvent;
use Modules\CRM\Events\QuoteRequestedEvent;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\LossReason;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityLineItem;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\OpportunityStageHistory;
use Modules\CRM\Support\WeightedValue;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Projects\Models\Project;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final readonly class OpportunityService implements OpportunityServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null,
        private ?SalesQuoteServiceInterface $quoteService = null
    ) {}

    public function createOpportunity(CreateOpportunityDTO $dto, string $actorUserId): Opportunity
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $stage = $this->resolveCreationStage($dto);

            $probability = (float) $stage->default_probability;
            $amount = (float) $dto->amount;
            $expectedRevenue = (float) WeightedValue::compute($amount, $probability);
            $oppNumber = 'OPP-'.date('Ymd').'-'.strtoupper(Str::random(4));
            $closeDate = $dto->expectedCloseDate ?: date('Y-m-d', strtotime('+30 days'));

            $opp = Opportunity::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'account_id' => $dto->accountId,
                'contact_id' => $dto->contactId,
                'lead_id' => $dto->leadId,
                'campaign_id' => $dto->campaignId,
                'stage_id' => $stage->id,
                'pipeline_id' => $stage->pipeline_id,
                'board_position' => $this->nextBoardPosition($dto->tenantId, (string) $stage->id),
                'owner_user_id' => $dto->ownerUserId ?? $actorUserId,
                'opportunity_number' => $oppNumber,
                'name' => $dto->name,
                'amount' => $amount,
                'expected_revenue' => $expectedRevenue,
                'currency' => $dto->currency,
                'probability' => $probability,
                'forecast_category' => $dto->forecastCategory ?? (in_array($stage->forecast_category, ['best_case', 'commit'], true) ? $stage->forecast_category : 'pipeline'),
                'expected_close_date' => $closeDate,
                'status' => 'open',
                'discount_percentage' => $dto->discountPercentage,
                'tags' => $dto->tags,
                'metadata' => $dto->metadata,
            ]);

            // Create Line Items if provided
            if (! empty($dto->lineItems)) {
                foreach ($dto->lineItems as $item) {
                    $qty = (float) ($item['quantity'] ?? 1.0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0.0);
                    $disc = (float) ($item['discount_percent'] ?? 0.0);
                    $total = round($qty * $unitPrice * (1 - ($disc / 100.0)), 2);

                    OpportunityLineItem::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $opp->tenant_id,
                        'opportunity_id' => $opp->id,
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'] ?? 'Item',
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'discount_percent' => $disc,
                        'total_price' => $total,
                    ]);
                }
            }

            // Check if workflow approval is required upfront
            $workflowCode = $this->checkWorkflowRequirement($opp, (string) $stage->code);
            if ($workflowCode !== null && $this->workflowEngine !== null) {
                try {
                    $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: $workflowCode,
                        approvable: $opp,
                        contextSnapshot: [
                            'opportunity_number' => $opp->opportunity_number,
                            'name' => $opp->name,
                            'amount' => (float) $opp->amount,
                            'currency' => $opp->currency,
                            'discount_percentage' => (float) $opp->discount_percentage,
                        ],
                        tenantId: (string) $opp->tenant_id,
                        companyId: (string) $opp->tenant_id,
                        initiatorUserId: $actorUserId
                    ));

                    $opp->update([
                        'workflow_instance_id' => (string) $instance->id,
                        'approval_status' => 'pending_approval',
                    ]);
                } catch (\Throwable $e) {
                    // Silently fail if workflow definition is not seeded yet
                }
            }

            // Audit Trail
            $this->auditLedger->record(
                action: 'OPPORTUNITY_CREATED',
                model: $opp,
                payloadBefore: null,
                payloadAfter: $opp->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            // Outbox Domain Event
            $event = new OpportunityCreatedEvent(
                opportunityId: (string) $opp->id,
                opportunityNumber: (string) $opp->opportunity_number,
                name: (string) $opp->name,
                accountId: (string) $opp->account_id,
                amount: (float) $opp->amount,
                expectedRevenue: (float) $opp->expected_revenue,
                currency: (string) $opp->currency,
                stageCode: (string) $stage->code,
                forecastCategory: (string) $opp->forecast_category,
                expectedCloseDate: (string) $opp->expected_close_date,
                actorUserId: $actorUserId,
                tenantId: (string) $opp->tenant_id,
                companyId: (string) $opp->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            OpportunityStageHistory::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $opp->tenant_id,
                'opportunity_id' => $opp->id,
                'from_stage_id' => null,
                'to_stage_id' => $stage->id,
                'changed_by_user_id' => $this->isUuid($actorUserId) ? $actorUserId : null,
                'amount' => $opp->amount,
                'probability' => $opp->probability,
                'weighted_amount' => $opp->expected_revenue,
            ]);
            $this->broadcastBoard($opp, 'created', $actorUserId);

            return $opp->fresh();
        });
    }

    public function changeStage(Opportunity $opp, UpdateOpportunityStageDTO $dto, string $actorUserId): Opportunity
    {
        return DB::transaction(function () use ($opp, $dto, $actorUserId) {
            $lockedOpp = Opportunity::where('id', $opp->id)->lockForUpdate()->firstOrFail();

            $targetStage = OpportunityStage::where('tenant_id', $lockedOpp->tenant_id)
                ->where('code', $dto->targetStageCode)
                ->firstOrFail();

            $this->assertSamePipeline($lockedOpp, $targetStage);
            $loss = $targetStage->is_lost ? $this->assertLossData((string) $lockedOpp->tenant_id, $dto) : null;

            $previousStage = $lockedOpp->stage;
            $previousStageCode = $previousStage ? (string) $previousStage->code : 'unknown';

            // Check workflow approval trigger on stage transition
            $workflowCode = $this->checkWorkflowRequirement($lockedOpp, (string) $targetStage->code);
            if ($workflowCode !== null && $this->workflowEngine !== null && $lockedOpp->approval_status !== 'approved') {
                try {
                    $instance = $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: $workflowCode,
                        approvable: $lockedOpp,
                        contextSnapshot: [
                            'opportunity_number' => $lockedOpp->opportunity_number,
                            'target_stage' => $targetStage->code,
                            'amount' => (float) $lockedOpp->amount,
                            'currency' => $lockedOpp->currency,
                        ],
                        tenantId: (string) $lockedOpp->tenant_id,
                        companyId: (string) $lockedOpp->tenant_id,
                        initiatorUserId: $actorUserId
                    ));

                    $lockedOpp->update([
                        'workflow_instance_id' => (string) $instance->id,
                        'approval_status' => 'pending_approval',
                    ]);
                } catch (\Throwable $e) {
                    // Fallback if workflow definition not registered
                }
            }

            $before = $lockedOpp->toArray();

            $probability = $dto->probability !== null ? (float) $dto->probability : (float) $targetStage->default_probability;
            $amount = $dto->amount !== null ? (float) $dto->amount : (float) $lockedOpp->amount;
            $expectedRevenue = (float) WeightedValue::compute($amount, $probability);

            $status = 'open';
            $actualCloseDate = $dto->actualCloseDate;

            if ($targetStage->is_won) {
                $status = 'won';
                $probability = 100.00;
                $expectedRevenue = $amount;
                $actualCloseDate = $actualCloseDate ?: date('Y-m-d');
            } elseif ($targetStage->is_lost) {
                $status = 'lost';
                $probability = 0.00;
                $expectedRevenue = 0.00;
                $actualCloseDate = $actualCloseDate ?: date('Y-m-d');
            }

            $stageCategory = in_array($targetStage->forecast_category, ['best_case', 'commit'], true) ? $targetStage->forecast_category : null;
            $forecastCategory = $dto->forecastCategory ?: ($targetStage->is_won ? 'closed' : ($targetStage->is_lost ? 'omitted' : ($stageCategory ?? (in_array($lockedOpp->status, ['won', 'lost'], true) ? 'pipeline' : $lockedOpp->forecast_category))));

            $lockedOpp->update([
                'stage_id' => $targetStage->id,
                'amount' => $amount,
                'probability' => $probability,
                'expected_revenue' => $expectedRevenue,
                'forecast_category' => $forecastCategory,
                'status' => $status,
                'actual_close_date' => $actualCloseDate,
                'win_loss_reason' => $loss !== null ? $loss['reason'] : ($status === 'open' ? null : ($dto->winLossReason ?? $lockedOpp->win_loss_reason)),
                'loss_reason_code' => $loss['code'] ?? null,
                'loss_notes' => $loss['notes'] ?? null,
                'competitor' => $loss !== null ? $loss['competitor'] : ($status === 'open' ? null : ($dto->competitor ?? $lockedOpp->competitor)),
                'pipeline_id' => $lockedOpp->pipeline_id ?? $targetStage->pipeline_id,
                'board_position' => $this->placeOnBoard($lockedOpp, $targetStage, $dto->position),
            ]);

            OpportunityStageHistory::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedOpp->tenant_id,
                'opportunity_id' => $lockedOpp->id,
                'from_stage_id' => $previousStage?->getKey(),
                'to_stage_id' => $targetStage->id,
                'changed_by_user_id' => $this->isUuid($actorUserId) ? $actorUserId : null,
                'amount' => $amount,
                'probability' => $probability,
                'weighted_amount' => $expectedRevenue,
                'loss_reason_code' => $loss['code'] ?? null,
                'winning_competitor' => $loss['competitor'] ?? null,
            ]);

            // Audit Trail
            $this->auditLedger->record(
                action: 'OPPORTUNITY_STAGE_CHANGED',
                model: $lockedOpp,
                payloadBefore: $before,
                payloadAfter: $lockedOpp->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            // Stage Changed Event
            $stageEvent = new OpportunityStageChangedEvent(
                opportunityId: (string) $lockedOpp->id,
                fromStageCode: $previousStageCode,
                toStageCode: (string) $targetStage->code,
                amount: $amount,
                probability: $probability,
                expectedRevenue: $expectedRevenue,
                actorUserId: $actorUserId,
                tenantId: (string) $lockedOpp->tenant_id,
                companyId: (string) $lockedOpp->tenant_id
            );
            $this->dispatcher->publish($stageEvent);
            event($stageEvent);

            // Specific Won / Lost Domain Events
            if ($targetStage->is_won) {
                $wonEvent = new OpportunityWonEvent(
                    opportunityId: (string) $lockedOpp->id,
                    accountId: (string) $lockedOpp->account_id,
                    contactId: $lockedOpp->contact_id ? (string) $lockedOpp->contact_id : null,
                    amount: $amount,
                    currency: (string) $lockedOpp->currency,
                    salesQuoteId: $lockedOpp->sales_quote_id ? (string) $lockedOpp->sales_quote_id : null,
                    actorUserId: $actorUserId,
                    tenantId: (string) $lockedOpp->tenant_id,
                    companyId: (string) $lockedOpp->tenant_id
                );
                $this->dispatcher->publish($wonEvent);
                event($wonEvent);

                // Auto request quote if none linked and contact exists
                $account = Account::find($lockedOpp->account_id);
                $hasContact = ! empty($lockedOpp->contact_id) || ! empty($account?->contact_id);

                if (empty($lockedOpp->sales_quote_id) && $hasContact) {
                    $this->requestQuote($lockedOpp, $actorUserId);
                }
            } elseif ($targetStage->is_lost) {
                $lostEvent = new OpportunityLostEvent(
                    opportunityId: (string) $lockedOpp->id,
                    accountId: (string) $lockedOpp->account_id,
                    amount: $amount,
                    reason: $dto->winLossReason,
                    competitor: $dto->competitor,
                    actorUserId: $actorUserId,
                    tenantId: (string) $lockedOpp->tenant_id,
                    companyId: (string) $lockedOpp->tenant_id
                );
                $this->dispatcher->publish($lostEvent);
                event($lostEvent);
            }

            $this->broadcastBoard($lockedOpp, $targetStage->is_lost ? 'lost' : ($targetStage->is_won ? 'won' : 'moved'), $actorUserId);

            return $lockedOpp->fresh();
        });
    }

    public function reposition(Opportunity $opp, int $position, string $actorUserId): Opportunity
    {
        return DB::transaction(function () use ($opp, $position, $actorUserId): Opportunity {
            $locked = Opportunity::where('id', $opp->id)->lockForUpdate()->firstOrFail();
            $stage = OpportunityStage::findOrFail($locked->stage_id);
            $locked->update(['board_position' => $this->placeOnBoard($locked, $stage, $position)]);
            $this->broadcastBoard($locked, 'reordered', $actorUserId);

            return $locked->fresh();
        });
    }

    public function updateNotes(Opportunity $opp, string $notes, bool $append, string $actorUserId): Opportunity
    {
        return DB::transaction(function () use ($opp, $notes, $append, $actorUserId): Opportunity {
            $locked = Opportunity::where('id', $opp->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['notes', 'notes_updated_at']);
            $text = trim($notes);
            $value = $append && trim((string) $locked->notes) !== ''
                ? rtrim((string) $locked->notes).'
['.now()->format('Y-m-d H:i').'] '.$text
                : $text;

            $locked->update(['notes' => $value, 'notes_updated_at' => now()]);

            $this->auditLedger->record(
                action: 'OPPORTUNITY_NOTES_UPDATED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->only(['notes', 'notes_updated_at']),
                moduleCode: 'MODULE_02_CRM'
            );
            $this->broadcastBoard($locked, 'noted', $actorUserId);

            return $locked->fresh();
        });
    }

    public function calculateExpectedRevenue(Opportunity $opp): float
    {
        return (float) WeightedValue::compute((string) $opp->amount, (string) $opp->probability);
    }

    public function requestQuote(Opportunity $opp, string $actorUserId): SalesQuote
    {
        return DB::transaction(function () use ($opp, $actorUserId) {
            $lockedOpp = Opportunity::where('id', $opp->id)->lockForUpdate()->firstOrFail();

            if (! empty($lockedOpp->sales_quote_id)) {
                $existingQuote = SalesQuote::find($lockedOpp->sales_quote_id);
                if ($existingQuote !== null) {
                    return $existingQuote;
                }
            }

            $account = Account::findOrFail($lockedOpp->account_id);
            $contactId = $lockedOpp->contact_id ?: $account->contact_id;

            if (empty($contactId)) {
                throw new InvalidArgumentException("Opportunity [{$opp->id}] has no associated contact to generate a quote.");
            }

            $quoteNumber = 'Q-CRM-'.date('Ymd').'-'.strtoupper(Str::random(4));
            $items = [];

            if ($lockedOpp->lineItems()->count() > 0) {
                foreach ($lockedOpp->lineItems as $line) {
                    $items[] = [
                        'product_id' => $line->product_id,
                        'description' => $line->description,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'tax_rate' => 22.0, // Standard VAT rate
                    ];
                }
            } else {
                $items[] = [
                    'description' => "Quote for Opportunity: {$lockedOpp->name}",
                    'quantity' => 1.0,
                    'unit_price' => (float) $lockedOpp->amount,
                    'tax_rate' => 22.0,
                ];
            }

            if ($this->quoteService !== null) {
                $quote = $this->quoteService->createQuote([
                    'tenant_id' => (string) $lockedOpp->tenant_id,
                    'contact_id' => (string) $contactId,
                    'quote_number' => $quoteNumber,
                    'date' => date('Y-m-d'),
                    'valid_until' => (string) $lockedOpp->expected_close_date,
                    'currency' => (string) $lockedOpp->currency,
                ], $items);
            } else {
                // Direct fallback creation if service not injected
                $quote = SalesQuote::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => (string) $lockedOpp->tenant_id,
                    'contact_id' => (string) $contactId,
                    'quote_number' => $quoteNumber,
                    'date' => date('Y-m-d'),
                    'valid_until' => (string) $lockedOpp->expected_close_date,
                    'currency' => (string) $lockedOpp->currency,
                    'subtotal' => (float) $lockedOpp->amount,
                    'total_net' => (float) $lockedOpp->amount,
                    'total_tax' => round((float) $lockedOpp->amount * 0.22, 2),
                    'total_gross' => round((float) $lockedOpp->amount * 1.22, 2),
                    'status' => 'draft',
                ]);
            }

            $lockedOpp->update([
                'sales_quote_id' => (string) $quote->id,
            ]);

            // Audit Trail
            $this->auditLedger->record(
                action: 'CRM_QUOTE_REQUESTED',
                model: $lockedOpp,
                payloadBefore: null,
                payloadAfter: [
                    'opportunity_id' => (string) $lockedOpp->id,
                    'sales_quote_id' => (string) $quote->id,
                    'quote_number' => $quoteNumber,
                    'amount' => (float) $lockedOpp->amount,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            // Domain Event
            $event = new QuoteRequestedEvent(
                opportunityId: (string) $lockedOpp->id,
                opportunityNumber: (string) $lockedOpp->opportunity_number,
                contactId: (string) $contactId,
                accountId: (string) $lockedOpp->account_id,
                amount: (float) $lockedOpp->amount,
                currency: (string) $lockedOpp->currency,
                lineItems: $items,
                actorUserId: $actorUserId,
                tenantId: (string) $lockedOpp->tenant_id,
                companyId: (string) $lockedOpp->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            return $quote;
        });
    }

    public function convertToProject(Opportunity $opp, string $actorUserId): array
    {
        return DB::transaction(function () use ($opp, $actorUserId) {
            $lockedOpp = Opportunity::where('id', $opp->id)->lockForUpdate()->firstOrFail();

            $account = Account::findOrFail($lockedOpp->account_id);
            $contactId = $lockedOpp->contact_id ?: $account->contact_id;

            $projectName = "Project: {$lockedOpp->name}";
            $projectCode = 'PRJ-'.strtoupper(Str::random(6));

            $project = Project::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => (string) $lockedOpp->tenant_id,
                'contact_id' => (string) $contactId,
                'project_number' => $projectCode,
                'name' => $projectName,
                'project_manager_user_id' => $actorUserId,
                'status' => 'planned',
                'planned_start_date' => date('Y-m-d'),
                'planned_end_date' => (string) $lockedOpp->expected_close_date,
                'contract_value' => (float) $lockedOpp->amount,
                'project_type' => 'fixed_price',
                'billing_method' => 'percentage_of_completion',
            ]);

            $lockedOpp->update([
                'project_id' => (string) $project->id,
            ]);

            $this->auditLedger->record(
                action: 'OPPORTUNITY_CONVERTED_TO_PROJECT',
                model: $lockedOpp,
                payloadBefore: null,
                payloadAfter: [
                    'opportunity_id' => (string) $lockedOpp->id,
                    'project_id' => (string) $project->id,
                    'project_code' => $projectCode,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            $event = new ProjectOpportunityCreatedEvent(
                opportunityId: (string) $lockedOpp->id,
                opportunityNumber: (string) $lockedOpp->opportunity_number,
                projectName: $projectName,
                contactId: (string) $contactId,
                accountId: (string) $lockedOpp->account_id,
                contractBudget: (float) $lockedOpp->amount,
                currency: (string) $lockedOpp->currency,
                expectedDeliveryDate: (string) $lockedOpp->expected_close_date,
                actorUserId: $actorUserId,
                tenantId: (string) $lockedOpp->tenant_id,
                companyId: (string) $lockedOpp->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            return [
                'opportunity' => $lockedOpp,
                'project_id' => (string) $project->id,
            ];
        });
    }

    public function checkWorkflowRequirement(Opportunity $opp, string $targetStageCode): ?string
    {
        // 1. High Value Opportunity (> €50,000)
        if ((float) $opp->amount >= 50000.0) {
            return 'HIGH_VALUE_OPPORTUNITY_APPROVAL';
        }

        // 2. Large Discount (> 20%)
        if ((float) $opp->discount_percentage >= 20.0) {
            return 'LARGE_DISCOUNT_APPROVAL';
        }

        // 3. Strategic Account Tier
        $account = $opp->account;
        if ($account !== null && $account->tier === 'strategic') {
            return 'STRATEGIC_ACCOUNT_APPROVAL';
        }

        // 4. Closed Lost with high value (Closure Exception)
        if ($targetStageCode === 'closed_lost' && (float) $opp->amount >= 25000.0) {
            return 'OPPORTUNITY_CLOSURE_EXCEPTION_APPROVAL';
        }

        return null;
    }

    /**
     * The stage a new deal starts in: the named stage (optionally inside the chosen pipeline), else the first open
     * stage of the pipeline (or of the tenant when no pipeline is given).
     */
    private function resolveCreationStage(CreateOpportunityDTO $dto): OpportunityStage
    {
        $base = OpportunityStage::where('tenant_id', $dto->tenantId)->where('is_active', true);

        if ($dto->pipelineId !== null) {
            $inPipeline = (clone $base)->where('pipeline_id', $dto->pipelineId);
            $stage = (clone $inPipeline)->where('code', $dto->stageCode ?? 'discovery')->first()
                ?? (clone $inPipeline)->where('is_won', false)->where('is_lost', false)->orderBy('sequence')->first();

            if ($stage === null) {
                throw ValidationException::withMessages(['pipeline_id' => [__('crm_pipeline.pipeline_has_no_stage')]]);
            }

            return $stage;
        }

        return (clone $base)->where('code', $dto->stageCode ?? 'discovery')->first()
            ?? (clone $base)->orderBy('sequence')->firstOrFail();
    }

    private function nextBoardPosition(string $tenantId, string $stageId): int
    {
        return ((int) Opportunity::where('tenant_id', $tenantId)->where('stage_id', $stageId)->max('board_position')) + 1;
    }

    /**
     * A deal belongs to one pipeline: it can only move between the stages of that pipeline.
     */
    private function assertSamePipeline(Opportunity $opp, OpportunityStage $target): void
    {
        if ($opp->pipeline_id !== null && $target->pipeline_id !== null && $opp->pipeline_id !== $target->pipeline_id) {
            throw ValidationException::withMessages(['target_stage_code' => [__('crm_pipeline.stage_in_other_pipeline')]]);
        }
    }

    /**
     * Closing as lost requires the structured questionnaire: a loss reason (from the seeded catalog when the tenant has
     * one) and the winning competitor.
     *
     * @return array{code: string|null, reason: string, competitor: string, notes: string|null}
     */
    private function assertLossData(string $tenantId, UpdateOpportunityStageDTO $dto): array
    {
        $errors = [];
        $competitor = trim((string) $dto->competitor);
        $code = $dto->lossReasonCode !== null && trim($dto->lossReasonCode) !== '' ? trim($dto->lossReasonCode) : null;
        $reason = null;

        $hasCatalog = LossReason::where('tenant_id', $tenantId)->where('is_active', true)->exists();

        if ($code !== null) {
            $catalogReason = LossReason::where('tenant_id', $tenantId)->where('is_active', true)->where('code', $code)->first();
            if ($catalogReason === null) {
                $errors['loss_reason_code'] = [__('crm_pipeline.loss_reason_invalid')];
            } else {
                $reason = $catalogReason->label();
                if ($catalogReason->requires_competitor && $competitor === '') {
                    $errors['competitor'] = [__('crm_pipeline.winning_competitor_required')];
                }
            }
        } elseif ($hasCatalog || trim((string) $dto->winLossReason) === '') {
            $errors['loss_reason_code'] = [__('crm_pipeline.loss_reason_required')];
        } else {
            $reason = trim((string) $dto->winLossReason);
        }

        if ($competitor === '') {
            $errors['competitor'] = [__('crm_pipeline.winning_competitor_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = $dto->lossNotes !== null && trim($dto->lossNotes) !== '' ? trim($dto->lossNotes) : null;

        return ['code' => $code, 'reason' => $notes ?? (string) $reason, 'competitor' => $competitor, 'notes' => $notes];
    }

    /**
     * Puts the deal at the requested position of the target column (or at its end) and renumbers the column.
     */
    private function placeOnBoard(Opportunity $opp, OpportunityStage $target, ?int $position): int
    {
        $siblings = Opportunity::where('tenant_id', $opp->tenant_id)
            ->where('stage_id', $target->id)
            ->where('id', '!=', $opp->id)
            ->orderBy('board_position')
            ->orderBy('created_at')
            ->get(['id', 'board_position']);

        $index = $position === null ? $siblings->count() : max(0, min($position, $siblings->count()));
        $ordered = $siblings->pluck('id')->all();
        array_splice($ordered, $index, 0, [(string) $opp->id]);

        foreach ($ordered as $order => $id) {
            if ($id !== (string) $opp->id) {
                Opportunity::where('id', $id)->update(['board_position' => $order]);
            }
        }

        return $index;
    }

    private function isUuid(string $value): bool
    {
        return Str::isUuid($value);
    }

    private function broadcastBoard(Opportunity $opp, string $change, string $actorUserId): void
    {
        $event = new OpportunityBoardUpdated(
            tenantId: (string) $opp->tenant_id,
            opportunityId: (string) $opp->id,
            change: $change,
            pipelineId: $opp->pipeline_id !== null ? (string) $opp->pipeline_id : null,
            stageId: (string) $opp->stage_id,
            actorUserId: $this->isUuid($actorUserId) ? $actorUserId : null,
        );

        // A broadcast outage (WebSocket server down) must never fail the deal change itself.
        DB::afterCommit(static function () use ($event): void {
            try {
                event($event);
            } catch (\Throwable $exception) {
                Log::warning('CRM board broadcast failed', ['error' => $exception->getMessage()]);
            }
        });
    }
}
