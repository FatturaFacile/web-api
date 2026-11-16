<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Manufacturing\Models\MfgBom;

/**
 * A bill of material of the manufacturing module that the change order touches.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $change_order_id
 * @property string $bom_id
 * @property string|null $note
 */
final class PlmAffectedBom extends BaseEnterpriseModel
{
    protected $table = 'plm_affected_boms';

    /**
     * @return BelongsTo<PlmChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(PlmChangeOrder::class, 'change_order_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }
}
