<?php

declare(strict_types=1);

namespace Modules\Invoicing\Policies;

use App\Models\User;
use Modules\Invoicing\Models\SalesQuote;

final class SalesQuotePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SalesQuote $quote): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, SalesQuote $quote): bool
    {
        return true;
    }

    public function delete(?User $user, SalesQuote $quote): bool
    {
        return true;
    }
}
