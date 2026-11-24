<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneUnverifiedUsersCommand extends Command
{
    protected $signature = 'auth:prune-unverified-users';

    protected $description = 'Delete self-registered accounts whose email address was never confirmed';

    public function handle(): int
    {
        // A query, not model events: these accounts never had roles or tokens, and their access request is deleted by cascade.
        $deleted = (int) User::withTrashed()
            ->whereNull('email_verified_at')
            ->whereNotNull('terms_accepted_at')
            ->where('created_at', '<', Carbon::now()->subDays((int) config('auth.registration.prune_unverified_after_days')))
            ->forceDelete();

        $this->info("Deleted {$deleted} unverified accounts.");

        return self::SUCCESS;
    }
}
