<?php

declare(strict_types=1);

namespace Modules\Treasury\Policies;

use App\Models\User;
use Modules\Treasury\Models\CashForecast;

final class CashForecastPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, CashForecast $forecast): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }
}
