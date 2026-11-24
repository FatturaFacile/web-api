<?php

declare(strict_types=1);

namespace Modules\Procurement\Policies;

use App\Models\User;
use Modules\Procurement\Models\PurchaseReceipt;

final class PurchaseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseReceipt $receipt): bool
    {
        return (string) $user->tenant_id === (string) $receipt->tenant_id;
    }
}
