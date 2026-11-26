<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Casts\DateOnly;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Carbon;
use Modules\Treasury\Currency\Domain\Enums\SyncRunStatus;
use Modules\Treasury\Currency\Domain\Enums\SyncTrigger;

/**
 * One run of a rate provider (ECB, SNB, ESTV). Scheduled runs belong to the platform (no tenant) and every company reads them;
 * a manual run belongs to the company that asked for it.
 *
 * @property string $provider
 * @property SyncRunStatus $status
 * @property SyncTrigger $trigger
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $rates_count
 * @property int $derived_count
 * @property Carbon|null $latest_rate_date
 * @property string|null $error_message
 * @property string|null $requested_by
 */
final class ExchangeRateSyncRun extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'exchange_rate_sync_runs';

    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'trigger' => SyncTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'latest_rate_date' => DateOnly::class,
            'rates_count' => 'integer',
            'derived_count' => 'integer',
        ];
    }
}
