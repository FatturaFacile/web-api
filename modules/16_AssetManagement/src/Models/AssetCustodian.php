<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model of the asset custodian records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $asset_id
 * @property string|null $custodian_user_id
 * @property string|null $assigned_by_user_id
 * @property string|null $department
 * @property Carbon|null $assigned_at
 * @property Carbon|null $expected_return_at
 * @property Carbon|null $returned_at
 * @property string|null $condition_on_checkout
 * @property string|null $condition_on_return
 * @property string|null $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetCustodian extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_custodians';

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function custodianUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
