<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A BOM component consumed by a routing operation, issued when the operation completes (backflush) or by hand.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $operation_id
 * @property string $bom_component_id
 * @property bool $backflush_on_completion
 * @property string|null $notes
 * @property-read MfgBomComponent|null $bomComponent
 */
final class MfgRoutingStepBomComponent extends BaseEnterpriseModel
{
    protected $table = 'mfg_routing_step_bom_components';

    protected function casts(): array
    {
        return [
            'backflush_on_completion' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MfgBomComponent, $this>
     */
    public function bomComponent(): BelongsTo
    {
        return $this->belongsTo(MfgBomComponent::class, 'bom_component_id');
    }
}
