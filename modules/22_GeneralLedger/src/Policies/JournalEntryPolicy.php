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

    /**
     * Posting, submitting, approving, rejecting or reversing a journal of the user's tenant. Whether the journal is in
     * the right status is decided by the services (409), the four-eyes rule by ManualJournalService (403).
     */
    public function process(User $user, JournalEntry $entry): bool
    {
        return $user->tenant_id === $entry->tenant_id;
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $user->tenant_id === $entry->tenant_id && $entry->status === 'draft';
    }
}
