<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Events\ActivityCompletedEvent;
use Modules\CRM\Events\MeetingScheduledEvent;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;

final readonly class ActivityService implements ActivityServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function logActivity(CreateActivityDTO $dto, string $actorUserId): Activity
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $activity = Activity::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'subject' => $dto->subject,
                'type' => $dto->type,
                'status' => 'pending',
                'priority' => $dto->priority,
                'due_date' => $dto->dueDate,
                'assigned_user_id' => $dto->assignedUserId ?? $actorUserId,
                'created_by_user_id' => $actorUserId,
                'related_type' => $dto->relatedType,
                'related_id' => $dto->relatedId,
                'duration_minutes' => $dto->durationMinutes,
                'location' => $dto->location,
                'notes' => $dto->notes,
                'metadata' => $dto->metadata,
            ]);

            // Update parent entity last interaction
            if ($dto->relatedType === 'account' && ! empty($dto->relatedId)) {
                Account::where('id', $dto->relatedId)->update(['last_activity_at' => now()]);
            } elseif ($dto->relatedType === 'lead' && ! empty($dto->relatedId)) {
                Lead::where('id', $dto->relatedId)->update(['updated_at' => now()]);
            }

            // Audit
            $this->auditLedger->record(
                action: 'CRM_ACTIVITY_LOGGED',
                model: $activity,
                payloadBefore: null,
                payloadAfter: $activity->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            // Specific Meeting Scheduled Event
            if ($dto->type === 'meeting') {
                $meetingEvent = new MeetingScheduledEvent(
                    activityId: (string) $activity->id,
                    subject: $dto->subject,
                    relatedType: $dto->relatedType ?? 'lead',
                    relatedId: $dto->relatedId ?? (string) Str::uuid7(),
                    scheduledAt: $dto->dueDate ?? date('Y-m-d H:i:s'),
                    durationMinutes: $dto->durationMinutes,
                    location: $dto->location,
                    actorUserId: $actorUserId,
                    tenantId: $dto->tenantId,
                    companyId: $dto->companyId ?? $dto->tenantId
                );
                $this->dispatcher->publish($meetingEvent);
                event($meetingEvent);
            }

            return $activity;
        });
    }

    public function completeActivity(Activity $activity, ?string $outcome, string $actorUserId): Activity
    {
        return DB::transaction(function () use ($activity, $outcome, $actorUserId) {
            $locked = Activity::where('id', $activity->id)->lockForUpdate()->firstOrFail();

            $before = $locked->toArray();
            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'outcome' => $outcome,
            ]);

            $this->auditLedger->record(
                action: 'CRM_ACTIVITY_COMPLETED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            $event = new ActivityCompletedEvent(
                activityId: (string) $locked->id,
                type: (string) $locked->type,
                subject: (string) $locked->subject,
                outcome: $outcome,
                relatedType: (string) $locked->related_type,
                relatedId: (string) $locked->related_id,
                actorUserId: $actorUserId,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) $locked->tenant_id
            );
            $this->dispatcher->publish($event);
            event($event);

            return $locked;
        });
    }

    public function getTimeline(string $tenantId, string $relatedType, string $relatedId): Collection
    {
        return Activity::where('tenant_id', $tenantId)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderByDesc('due_date')
            ->orderByDesc('created_at')
            ->get();
    }
}
