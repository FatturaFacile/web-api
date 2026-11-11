<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Retention rule of a company for one table of the personal data registry: rows older than retention_days are
 * anonymised by the scheduled command privacy:apply-retention.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entry_key
 * @property int $retention_days
 * @property string $action
 * @property bool $is_active
 * @property string|null $legal_basis_note
 */
final class PrivacyRetentionPolicy extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_retention_policies';

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'is_active' => 'boolean',
            'last_run_at' => 'immutable_datetime',
        ];
    }
}
