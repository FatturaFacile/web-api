<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->belongsToSameTenant($user, $expense);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Expense $expense): bool
    {
        if (! $this->belongsToSameTenant($user, $expense)) {
            return false;
        }

        // Expenses already locked by accountant export cannot be modified
        return ! $expense->isExported();
    }

    public function delete(User $user, Expense $expense): bool
    {
        if (! $this->belongsToSameTenant($user, $expense)) {
            return false;
        }

        // Expenses already locked by accountant export cannot be deleted
        return ! $expense->isExported();
    }

    public function uploadAttachment(User $user, Expense $expense): bool
    {
        return $this->belongsToSameTenant($user, $expense);
    }

    public function export(User $user): bool
    {
        return true;
    }

    private function belongsToSameTenant(User $user, Expense $expense): bool
    {
        if (! empty($user->tenant_id) && $user->tenant_id === $expense->tenant_id) {
            return true;
        }

        return $user->tenants()->where('tenants.id', $expense->tenant_id)->exists();
    }
}
