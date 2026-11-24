<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryNote;
use App\Models\User;
use Modules\Logistics\Support\LogisticsPermissions;

class DeliveryNotePolicy
{
    public function viewAny(User $user): bool
    {
        return LogisticsPermissions::allows($user, LogisticsPermissions::DDT_VIEW);
    }

    public function view(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note)
            && LogisticsPermissions::allows($user, LogisticsPermissions::DDT_VIEW);
    }

    public function create(User $user): bool
    {
        return LogisticsPermissions::allows($user, LogisticsPermissions::DDT_CREATE);
    }

    public function update(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note)
            && LogisticsPermissions::allows($user, LogisticsPermissions::DDT_UPDATE);
    }

    public function delete(User $user, DeliveryNote $note): bool
    {
        if (! $this->belongsToSameTenant($user, $note)) {
            return false;
        }

        // Cannot delete if already invoiced
        return $note->status !== 'invoiced'
            && LogisticsPermissions::allows($user, LogisticsPermissions::DDT_DELETE);
    }

    public function invoice(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note)
            && LogisticsPermissions::allows($user, LogisticsPermissions::DDT_INVOICE);
    }

    public function download(User $user, DeliveryNote $note): bool
    {
        return $this->belongsToSameTenant($user, $note)
            && LogisticsPermissions::allows($user, LogisticsPermissions::DDT_PDF);
    }

    private function belongsToSameTenant(User $user, DeliveryNote $note): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $note->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $note->tenant_id)->exists();
    }
}
