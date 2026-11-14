<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One qualification of a supplier against the weighted checklist: draft while scores are entered, completed once the
 * weighted score (0 to 100), the band and the outcome are fixed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string|null $onboarding_id
 * @property string $status
 * @property string|null $total_score
 * @property string|null $band
 * @property string|null $outcome
 * @property string|null $assessed_by
 * @property Carbon|null $completed_at
 * @property string|null $notes
 */
final class QualificationAssessment extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Completed = 'completed';

    public const string Qualified = 'qualified';

    public const string Conditional = 'conditional';

    public const string NotQualified = 'not_qualified';

    protected $table = 'sp_qualification_assessments';

    protected function casts(): array
    {
        return ['total_score' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    /**
     * @return HasMany<QualificationScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(QualificationScore::class, 'assessment_id');
    }
}
