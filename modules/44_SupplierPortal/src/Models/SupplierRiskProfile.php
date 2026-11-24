<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * The risk flag of a supplier with the reasons that raised it (expired documents, blocked VAT, failed checks...).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $level
 * @property int $score
 * @property list<array{code: string, points: int, detail: ?string}>|null $reasons
 * @property Carbon|null $evaluated_at
 * @property Supplier|null $supplier
 */
final class SupplierRiskProfile extends BaseEnterpriseModel
{
    protected $table = 'sp_risk_profiles';

    protected function casts(): array
    {
        return ['score' => 'integer', 'reasons' => 'array', 'evaluated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
