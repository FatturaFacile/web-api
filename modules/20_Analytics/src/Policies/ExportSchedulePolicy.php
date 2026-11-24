<?php

declare(strict_types=1);

namespace Modules\Analytics\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Analytics\Models\ExportSchedule;

/**
 * A schedule belongs to the user who created it, who is the identity its exports run as. Only that user, or an owner or
 * administrator of the company, sees, changes or deletes it.
 */
final class ExportSchedulePolicy
{
    /**
     * See, change or delete the schedule.
     */
    public function manage(User $user, ExportSchedule $schedule): bool
    {
        if ((string) $schedule->user_id === (string) $user->id) {
            return true;
        }

        return in_array($user->getRoleInTenant((string) app(TenantContext::class)->getTenantId()), ['owner', 'admin'], true);
    }
}
