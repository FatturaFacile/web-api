<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A file produced by a scheduled export, kept on the private disk until it expires and reachable through a signed
 * link. Manual downloads are streamed and not stored; both are audit logged.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property ?string $designed_report_id
 * @property ?string $schedule_id
 * @property string $dataset
 * @property string $format
 * @property int $row_count
 * @property bool $truncated
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $file_name
 * @property ?string $sha256
 * @property int $size_bytes
 * @property ?string $document_id
 * @property ?Carbon $expires_at
 */
final class ExportRun extends BaseEnterpriseModel
{
    protected $table = 'analytics_export_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['truncated' => 'boolean', 'expires_at' => 'datetime', 'row_count' => 'integer', 'size_bytes' => 'integer'];
    }
}
