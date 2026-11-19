<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use App\Support\Database\TransactionLock;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Document numbers of the WMS (WV-2027-0001 for waves, CD-2027-0001 for cross-dock orders): one sequence per company,
 * kind and year, taken under a named transaction lock so two requests never get the same number.
 */
final class WmsNumbering
{
    /**
     * @param  class-string<BaseEnterpriseModel>  $model
     */
    public function next(string $model, string $column, string $prefix, string $tenantId): string
    {
        TransactionLock::acquire("wms:{$prefix}:{$tenantId}");

        $stem = $prefix.'-'.now()->format('Y').'-';
        $last = $model::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where($column, 'like', $stem.'%')
            ->orderByDesc($column)
            ->value($column);
        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
