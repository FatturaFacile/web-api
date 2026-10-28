<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Quote $quote): bool
    {
        if (! $this->belongsToSameTenant($user, $quote)) {
            return false;
        }

        // Accepted or invoiced quotes cannot be modified
        return ! in_array($quote->status, ['accepted', 'invoiced'], true);
    }

    public function delete(User $user, Quote $quote): bool
    {
        if (! $this->belongsToSameTenant($user, $quote)) {
            return false;
        }

        // Cannot delete accepted or converted quotes
        return ! in_array($quote->status, ['accepted', 'invoiced'], true);
    }

    public function sign(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function convert(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    public function download(User $user, Quote $quote): bool
    {
        return $this->belongsToSameTenant($user, $quote);
    }

    private function belongsToSameTenant(User $user, Quote $quote): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $quote->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $quote->tenant_id)->exists();
    }
}
