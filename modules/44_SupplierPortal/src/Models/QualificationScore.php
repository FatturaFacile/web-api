<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The score of a supplier on one criterion of an assessment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $assessment_id
 * @property string $criterion_id
 * @property string $score
 * @property string|null $notes
 * @property QualificationCriterion|null $criterion
 */
final class QualificationScore extends BaseEnterpriseModel
{
    protected $table = 'sp_qualification_scores';

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<QualificationCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(QualificationCriterion::class, 'criterion_id');
    }
}
