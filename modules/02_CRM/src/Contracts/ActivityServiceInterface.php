<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Illuminate\Support\Collection;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Models\Activity;

interface ActivityServiceInterface
{
    public function logActivity(CreateActivityDTO $dto, string $actorUserId): Activity;

    public function completeActivity(Activity $activity, ?string $outcome, string $actorUserId): Activity;

    public function getTimeline(string $tenantId, string $relatedType, string $relatedId): Collection;

    /**
     * The timeline of a record including the activities of the records linked to it (a contact's accounts,
     * opportunities and originating leads; an account's opportunities and contact), newest first.
     *
     * @return Collection<int, Activity>
     */
    public function getUnifiedTimeline(string $tenantId, string $relatedType, string $relatedId): Collection;

    /**
     * Reschedules, reassigns or cancels an open activity.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateActivity(Activity $activity, array $changes, string $actorUserId): Activity;
}
