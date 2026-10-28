<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceReport;
use App\Models\User;

class ServiceReportPolicy
{
    /**
     * Determine whether the user can view any service reports.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the service report.
     */
    public function view(User $user, ServiceReport $report): bool
    {
        return $this->belongsToSameTenant($user, $report);
    }

    /**
     * Determine whether the user can create service reports.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the service report.
     */
    public function update(User $user, ServiceReport $report): bool
    {
        if (! $this->belongsToSameTenant($user, $report)) {
            return false;
        }

        // Cannot update if already signed or invoiced
        if ($report->signed_at !== null || $report->invoiced_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can collect customer signature on the service report.
     */
    public function sign(User $user, ServiceReport $report): bool
    {
        if (! $this->belongsToSameTenant($user, $report)) {
            return false;
        }

        return $report->signed_at === null && $report->invoiced_at === null;
    }

    /**
     * Determine whether the user can generate an invoice from the service report.
     */
    public function generateInvoice(User $user, ServiceReport $report): bool
    {
        if (! $this->belongsToSameTenant($user, $report)) {
            return false;
        }

        return $report->signed_at !== null && $report->invoiced_at === null;
    }

    /**
     * Determine whether the user can upload work photos to the service report.
     */
    public function uploadPhoto(User $user, ServiceReport $report): bool
    {
        return $this->update($user, $report);
    }

    /**
     * Determine whether the user can download or stream the PDF of the service report.
     */
    public function pdf(User $user, ServiceReport $report): bool
    {
        return $this->view($user, $report);
    }

    /**
     * Determine whether the user can delete the service report.
     */
    public function delete(User $user, ServiceReport $report): bool
    {
        if (! $this->belongsToSameTenant($user, $report)) {
            return false;
        }

        // Once signed or invoiced, reports cannot be deleted to preserve financial audit trail
        return $report->signed_at === null && $report->invoiced_at === null;
    }

    /**
     * Check whether user belongs to the same tenant as the service report.
     */
    private function belongsToSameTenant(User $user, ServiceReport $report): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $report->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $report->tenant_id)->exists();
    }
}
