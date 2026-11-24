<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Log of one execution (or dry run) of a retention policy: how many rows matched and how many were anonymised.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $policy_id
 * @property string $entry_key
 * @property bool $dry_run
 * @property int $matched_count
 * @property int $processed_count
 * @property string $status
 */
final class PrivacyRetentionRun extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_retention_runs';

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'matched_count' => 'integer',
            'processed_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }
}
