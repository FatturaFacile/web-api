<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->belongsToSameTenant($user, $payment);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->belongsToSameTenant($user, $payment);
    }

    private function belongsToSameTenant(User $user, Payment $payment): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $payment->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $payment->tenant_id)->exists();
    }
}
