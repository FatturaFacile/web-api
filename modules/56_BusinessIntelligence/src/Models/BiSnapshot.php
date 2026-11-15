<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use Database\Factories\BiSnapshotFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * The frozen result of a saved query or of the widgets of a dashboard on a given day, so the figures of that day can be
 * compared with the ones of today even after the source data changes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string $source_id
 * @property string|null $taken_by
 * @property \Illuminate\Support\Carbon $taken_on
 * @property string $trigger
 * @property string $status
 * @property string|null $error
 * @property int $row_count
 * @property array<string, mixed>|null $payload
 */
#[UseFactory(BiSnapshotFactory::class)]
final class BiSnapshot extends BaseEnterpriseModel
{
    public const string SavedQuery = 'saved_query';

    public const string Dashboard = 'dashboard';

    public const string Ok = 'ok';

    public const string Failed = 'failed';

    protected $table = 'bi_snapshots';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['taken_on' => 'date', 'payload' => 'array', 'row_count' => 'integer'];
    }
}
