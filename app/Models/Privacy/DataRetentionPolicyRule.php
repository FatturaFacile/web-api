<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use App\Enums\Privacy\RetentionExpiryAction;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Retention rule of one module (e.g. MOD_070_CANDIDATES). Statutory locked rules (fiscal modules) are never purged.
 *
 * @property string $module_code
 * @property int $retention_days
 * @property RetentionExpiryAction $action_on_expiry
 * @property bool $is_statutory_locked
 * @property bool $is_enabled
 */
final class DataRetentionPolicyRule extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_retention_rules';

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'action_on_expiry' => RetentionExpiryAction::class,
            'is_statutory_locked' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
