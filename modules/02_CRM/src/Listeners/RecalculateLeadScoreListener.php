<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use Modules\CRM\Events\ActivityCompletedEvent;
use Modules\CRM\Events\ActivityLoggedEvent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Services\LeadScoringService;

/**
 * Keeps engagement-based lead scores current: when an activity is logged on or completed for a lead, the lead is
 * scored again, inside the same transaction as the activity.
 */
final readonly class RecalculateLeadScoreListener
{
    public function __construct(private LeadScoringService $scoringService) {}

    public function handle(ActivityLoggedEvent|ActivityCompletedEvent $event): void
    {
        if ($event->relatedType !== 'lead' || empty($event->relatedId)) {
            return;
        }

        $lead = Lead::where('tenant_id', $event->tenantId)->find($event->relatedId);

        if ($lead !== null) {
            $this->scoringService->recalculate($lead);
        }
    }
}
