<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Database\Factories\MarketplaceSyncRunFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One synchronisation of a resource of an installation: when it ran, how it ended and how many records it handled. The
 * message never holds a credential.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $installation_id
 * @property string $resource
 * @property string $trigger
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $records_processed
 * @property int $records_failed
 * @property string|null $message
 * @property string|null $triggered_by
 */
#[UseFactory(MarketplaceSyncRunFactory::class)]
final class MarketplaceSyncRun extends BaseEnterpriseModel
{
    public const string Running = 'running';

    public const string Succeeded = 'succeeded';

    public const string Failed = 'failed';

    public const string RateLimited = 'rate_limited';

    /** @var list<string> */
    public const array Statuses = [self::Running, self::Succeeded, self::Failed, self::RateLimited];

    protected $table = 'marketplace_sync_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'records_processed' => 'integer', 'records_failed' => 'integer'];
    }

    /**
     * @return BelongsTo<MarketplaceInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(MarketplaceInstallation::class, 'installation_id');
    }
}
