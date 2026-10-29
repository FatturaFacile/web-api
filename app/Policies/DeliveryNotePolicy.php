<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryNote;
use App\Models\User;

class DeliveryNotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note);
    }

    public function delete(User $user, DeliveryNote $note): bool
    {
        if (! $this->belongsToSameTenant($user, $note)) {
            return false;
        }

        // Cannot delete if already invoiced
        return $note->status !== 'invoiced';
    }

    public function invoice(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note);
    }

    public function download(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note);
    }

    private function belongsToSameTenant(User $user, DeliveryNote $note): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $note->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $note->tenant_id)->exists();
    }
}
