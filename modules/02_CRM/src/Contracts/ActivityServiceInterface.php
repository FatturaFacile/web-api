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
}
