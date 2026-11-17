<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Concerns\AuditsWithoutCandidatePii;

/**
 * One stage change of a candidate application.
 *
 * @property string $id
 * @property string $application_id
 * @property string|null $from_stage
 * @property string $to_stage
 * @property string|null $actor_user_id
 * @property string|null $note
 */
final class ApplicationStageHistory extends BaseEnterpriseModel
{
    use AuditsWithoutCandidatePii;

    protected $table = 'rec_application_stage_history';

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class, 'application_id');
    }
}
