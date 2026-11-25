<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One on-demand or scheduled retrieval of transactions from a PSD2 / SIX bLink provider.
 *
 * @property string $consent_id
 * @property string $driver
 * @property string $status
 * @property CarbonInterface $from_date
 * @property CarbonInterface $to_date
 * @property int $fetched
 * @property int $imported
 * @property int $duplicates
 * @property string|null $bank_statement_id
 * @property string|null $error
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property string|null $triggered_by
 */
final class OpenBankingSyncRun extends BaseEnterpriseModel
{
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $table = 'open_banking_sync_runs';

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'fetched' => 'integer',
            'imported' => 'integer',
            'duplicates' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
