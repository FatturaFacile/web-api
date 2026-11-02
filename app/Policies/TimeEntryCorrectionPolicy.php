<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class TimeEntryCorrectionPolicy
{
    public function create(User $user, TimeEntry $entry): bool
    {
        return $this->sameTenant($user, $entry)
            && ($user->id === $entry->user_id || $user->can('approve-attendance-corrections'));
    }

    public function viewForEntry(User $user, TimeEntry $entry): bool
    {
        return $this->sameTenant($user, $entry)
            && ($user->id === $entry->user_id || $user->can('view-all-attendance') || $user->can('approve-attendance-corrections'));
    }

    public function review(User $user, TimeEntryCorrection $correction): bool
    {
        $entry = $correction->timeEntry;

        return $entry !== null
            && $this->sameTenant($user, $entry)
            && $correction->tenant_id === $entry->tenant_id
            && $user->id !== $correction->requested_by
            && $user->id !== $entry->user_id
            && $user->can('approve-attendance-corrections');
    }

    private function sameTenant(User $user, TimeEntry $entry): bool
    {
        return app(TenantContext::class)->getTenantId() === $entry->tenant_id
            && $user->belongsToTenant((string) $entry->tenant_id);
    }
}
