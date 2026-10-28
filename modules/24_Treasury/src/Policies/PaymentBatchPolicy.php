<?php

declare(strict_types=1);

namespace Modules\Treasury\Policies;

use App\Models\User;
use Modules\Treasury\Models\PaymentBatch;

final class PaymentBatchPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, PaymentBatch $batch): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, PaymentBatch $batch): bool
    {
        return true;
    }

    public function delete(?User $user, PaymentBatch $batch): bool
    {
        return true;
    }

    public function execute(?User $user, PaymentBatch $batch): bool
    {
        return true;
    }
}
