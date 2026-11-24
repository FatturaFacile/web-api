<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee considered to take over a critical position, with the readiness and the risk of losing the person.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $succession_position_id
 * @property string $employee_id
 * @property string $readiness
 * @property string $risk_of_loss
 * @property string $potential
 * @property int $ranking
 * @property string|null $development_plan
 */
final class SuccessionCandidate extends BaseEnterpriseModel
{
    public const string ReadyNow = 'ready_now';

    /** @var list<string> */
    public const array Readiness = ['ready_now', 'ready_1_2_years', 'ready_3_plus_years'];

    /** @var list<string> */
    public const array Levels = ['low', 'medium', 'high'];

    protected $table = 'tl_succession_candidates';

    protected function casts(): array
    {
        return ['ranking' => 'integer'];
    }

    /**
     * @return BelongsTo<SuccessionPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(SuccessionPosition::class, 'succession_position_id');
    }
}
