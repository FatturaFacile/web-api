<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A position the company cannot leave vacant. The position and the incumbent are read from human resources by id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $position_id
 * @property string $title
 * @property string|null $incumbent_employee_id
 * @property string $criticality
 * @property string $vacancy_risk
 * @property string $status
 * @property string|null $notes
 */
final class SuccessionPosition extends BaseEnterpriseModel
{
    public const string Active = 'active';

    public const string Archived = 'archived';

    /** @var list<string> */
    public const array Criticality = ['low', 'medium', 'high', 'critical'];

    /** @var list<string> */
    public const array VacancyRisks = ['low', 'medium', 'high'];

    protected $table = 'tl_succession_positions';

    /**
     * @return HasMany<SuccessionCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(SuccessionCandidate::class, 'succession_position_id')->orderBy('ranking');
    }
}
