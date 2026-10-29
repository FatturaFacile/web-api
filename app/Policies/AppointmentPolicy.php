<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->belongsToSameTenant($user, $appointment);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        if (! $this->belongsToSameTenant($user, $appointment)) {
            return false;
        }

        return ! $appointment->isCancelled();
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        if (! $this->belongsToSameTenant($user, $appointment)) {
            return false;
        }

        // Cannot delete if already converted to a service report
        return $appointment->service_report_id === null;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        if (! $this->belongsToSameTenant($user, $appointment)) {
            return false;
        }

        return ! $appointment->isCancelled() && $appointment->service_report_id === null;
    }

    public function convert(User $user, Appointment $appointment): bool
    {
        if (! $this->belongsToSameTenant($user, $appointment)) {
            return false;
        }

        return ! $appointment->isCancelled() && $appointment->service_report_id === null;
    }

    private function belongsToSameTenant(User $user, Appointment $appointment): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $appointment->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $appointment->tenant_id)->exists();
    }
}
