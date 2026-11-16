<?php

declare(strict_types=1);

namespace Modules\DataExport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * History row of one export: who asked for which dataset and period, how it went and where the file is stored.
 */
final class ExportJob extends BaseEnterpriseModel
{
    public const string Queued = 'queued';

    public const string Running = 'running';

    public const string Completed = 'completed';

    public const string Failed = 'failed';

    /**
     * @var list<string>
     */
    public const array Datasets = ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive'];

    protected $table = 'export_jobs';

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Whether a stored file can still be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this->status === self::Completed && $this->path !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
