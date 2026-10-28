<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->belongsToSameTenant($user, $contact);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->belongsToSameTenant($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        if (! $this->belongsToSameTenant($user, $contact)) {
            return false;
        }

        // Contacts with existing invoices, quotes or service reports cannot be deleted
        return $contact->invoices()->count() === 0;
    }

    private function belongsToSameTenant(User $user, Contact $contact): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $contact->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $contact->tenant_id)->exists();
    }
}
