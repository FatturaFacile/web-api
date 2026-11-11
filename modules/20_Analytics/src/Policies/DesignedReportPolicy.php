<?php

declare(strict_types=1);

namespace Modules\Analytics\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Analytics\Models\DesignedReport;

/**
 * Who may use a designed report. It is private to its owner unless shared with the company or with named users; only the
 * owner, or an owner or administrator of the company, changes, deletes or schedules it. Running it is further limited by
 * the module of its dataset, which the report engine checks.
 */
final class DesignedReportPolicy
{
    /**
     * Open or run the report.
     */
    public function view(User $user, DesignedReport $report): bool
    {
        return $report->is_shared
            || (string) $report->user_id === (string) $user->id
            || in_array((string) $user->id, array_map('strval', $report->shared_with), true);
    }

    /**
     * Change, delete or schedule the report.
     */
    public function manage(User $user, DesignedReport $report): bool
    {
        if ((string) $report->user_id === (string) $user->id) {
            return true;
        }

        return in_array($user->getRoleInTenant((string) app(TenantContext::class)->getTenantId()), ['owner', 'admin'], true);
    }
}
