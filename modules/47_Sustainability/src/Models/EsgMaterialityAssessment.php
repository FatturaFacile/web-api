<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A double materiality assessment (ESRS 1): each topic is scored 0-5 on impact materiality and on financial materiality
 * and is material when either score reaches the threshold. An approved assessment is final.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $reporting_period_id
 * @property string $threshold
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 */
final class EsgMaterialityAssessment extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Approved = 'approved';

    protected $table = 'esg_materiality_assessments';

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'threshold' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<EsgMaterialityTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(EsgMaterialityTopic::class, 'assessment_id')->orderBy('esrs_code')->orderBy('name');
    }
}
