<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Policies;

use App\Models\User;
use Modules\GeneralLedger\Models\JournalEntry;

final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->tenant_id === $entry->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        return $user->tenant_id === $entry->tenant_id && $entry->status === 'draft';
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $user->tenant_id === $entry->tenant_id && $entry->status === 'draft';
    }
}
