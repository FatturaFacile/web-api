<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\StockReservation;

final class StockReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockReservation $reservation): bool
    {
        return (string) $user->tenant_id === (string) $reservation->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function release(User $user, StockReservation $reservation): bool
    {
        return (string) $user->tenant_id === (string) $reservation->tenant_id
            && $reservation->status === 'active';
    }

    public function delete(User $user, StockReservation $reservation): bool
    {
        return (string) $user->tenant_id === (string) $reservation->tenant_id;
    }
}
