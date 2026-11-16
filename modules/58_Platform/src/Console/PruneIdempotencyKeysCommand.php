<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Models\IdempotencyKey;

/**
 * Deletes the stored responses of Idempotency-Keys past their time to live.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'platform:prune-idempotency-keys';

    protected $description = 'Delete expired Idempotency-Key records';

    public function handle(): int
    {
        $deleted = IdempotencyKey::query()->withoutGlobalScopes()->where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deleted} expired idempotency keys.");

        return self::SUCCESS;
    }
}
