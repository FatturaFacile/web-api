<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Tenant;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Enums\ConsolidationMethod;

/**
 * A legal entity (tenant) of a consolidation group with the share of it that the group owns.
 *
 * @property string $id
 * @property string $group_id
 * @property string $member_tenant_id
 * @property string $ownership_percentage
 * @property ConsolidationMethod $consolidation_method
 * @property bool $is_parent
 * @property string|null $holder_tenant_id
 * @property int $fiscal_year_offset_months
 */
final class ConsolidationMember extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_members';

    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:4',
            'consolidation_method' => ConsolidationMethod::class,
            'is_parent' => 'boolean',
            'fiscal_year_offset_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConsolidationGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConsolidationGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function memberTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'member_tenant_id')->withDefault();
    }
}
