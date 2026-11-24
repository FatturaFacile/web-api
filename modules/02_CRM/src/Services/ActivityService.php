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
use Modules\CRM\Events\ActivityLoggedEvent;
use Modules\CRM\Events\MeetingScheduledEvent;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;

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
                'reminder_at' => $dto->reminderAt,
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

            $this->dispatcher->publishAndDispatch(new ActivityLoggedEvent(
                activityId: (string) $activity->id,
                type: (string) $activity->type,
                relatedType: $activity->related_type,
                relatedId: $activity->related_id,
                assignedUserId: $activity->assigned_user_id,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId ?? $dto->tenantId
            ));

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

    /**
     * Collects the records linked to the requested one and returns their activities together, so the timeline of a
     * customer shows every interaction whatever record it was logged on.
     *
     * @return Collection<int, Activity>
     */
    public function getUnifiedTimeline(string $tenantId, string $relatedType, string $relatedId): Collection
    {
        $related = ['lead' => [], 'account' => [], 'contact' => [], 'opportunity' => [], 'campaign' => []];
        $related[$relatedType][] = $relatedId;

        if ($relatedType === 'contact') {
            $related['account'] = Account::where('tenant_id', $tenantId)->where('contact_id', $relatedId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $related['opportunity'] = Opportunity::where('tenant_id', $tenantId)
                ->where(fn ($query) => $query->where('contact_id', $relatedId)->orWhereIn('account_id', $related['account']))
                ->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $related['lead'] = Lead::where('tenant_id', $tenantId)->where('converted_contact_id', $relatedId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        } elseif ($relatedType === 'account') {
            $account = Account::where('tenant_id', $tenantId)->find($relatedId);
            $related['opportunity'] = Opportunity::where('tenant_id', $tenantId)->where('account_id', $relatedId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $related['contact'] = $account?->contact_id !== null ? [(string) $account->contact_id] : [];
            $related['lead'] = Lead::where('tenant_id', $tenantId)->where('converted_account_id', $relatedId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        } elseif ($relatedType === 'lead') {
            $lead = Lead::where('tenant_id', $tenantId)->find($relatedId);
            $related['opportunity'] = $lead?->converted_opportunity_id !== null ? [(string) $lead->converted_opportunity_id] : [];
        }

        return Activity::where('tenant_id', $tenantId)
            ->where(function ($query) use ($related): void {
                foreach ($related as $type => $ids) {
                    if ($ids !== []) {
                        $query->orWhere(fn ($inner) => $inner->where('related_type', $type)->whereIn('related_id', $ids));
                    }
                }
            })
            ->orderByRaw('COALESCE(completed_at, due_date, created_at) DESC')
            ->limit(500)
            ->get();
    }

    /**
     * Applies the allowed changes to an open activity. A new reminder time re-arms the reminder.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateActivity(Activity $activity, array $changes, string $actorUserId): Activity
    {
        return DB::transaction(function () use ($activity, $changes): Activity {
            $locked = Activity::where('id', $activity->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'completed') {
                throw new \DomainException('A completed activity cannot be changed.');
            }

            $before = $locked->toArray();
            $allowed = array_intersect_key($changes, array_flip(['subject', 'priority', 'due_date', 'assigned_user_id', 'reminder_at', 'notes', 'location', 'duration_minutes', 'status']));

            if (array_key_exists('reminder_at', $allowed)) {
                $allowed['reminder_sent_at'] = null;
            }

            $locked->update($allowed);

            $this->auditLedger->record(
                action: 'CRM_ACTIVITY_UPDATED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $locked;
        });
    }
}
