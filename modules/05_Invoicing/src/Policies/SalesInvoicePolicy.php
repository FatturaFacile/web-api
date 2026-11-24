<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use Modules\Invoicing\Models\SalesInvoice;

final class SalesInvoicePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SalesInvoice $invoice): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, SalesInvoice $invoice): bool
    {
        return true;
    }

    public function delete(?User $user, SalesInvoice $invoice): bool
    {
        return true;
    }
}
