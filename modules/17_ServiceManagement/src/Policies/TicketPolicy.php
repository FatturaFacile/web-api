<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use Modules\ServiceManagement\Models\Ticket;

final class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id) && ! $ticket->is_locked;
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id) && ! $ticket->is_locked;
    }

    public function escalate(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id) && ! $ticket->is_locked;
    }

    public function resolve(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id) && ! $ticket->is_locked;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $this->belongsToTenant($user, (string) $ticket->tenant_id) && ! $ticket->is_locked;
    }

    private function belongsToTenant(User $user, string $tenantId): bool
    {
        $userTenant = (string) ($user->current_tenant_id ?? $user->tenant_id ?? '');

        return $userTenant === '' || $userTenant === $tenantId;
    }
}
