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

        // To comply with statutory 10-year fiscal retention and audit requirements,
        // contacts with any historical fiscal or operational records (including soft-deleted) cannot be deleted.
        if ($contact->invoices()->withTrashed()->exists()) {
            return false;
        }

        if ($contact->quotes()->withTrashed()->exists()) {
            return false;
        }

        if ($contact->salesOrders()->withTrashed()->exists()) {
            return false;
        }

        if ($contact->deliveryNotes()->withTrashed()->exists()) {
            return false;
        }

        if ($contact->serviceReports()->withTrashed()->exists()) {
            return false;
        }

        return true;
    }

    private function belongsToSameTenant(User $user, Contact $contact): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $contact->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $contact->tenant_id)->exists();
    }
}
