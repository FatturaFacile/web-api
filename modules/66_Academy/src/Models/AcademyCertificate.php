<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The certificate issued to a user who completed every mission of a track. The code is the public verification handle.
 *
 * @property string $id
 * @property string $user_id
 * @property string $track_id
 * @property string $certificate_code
 * @property string $holder_name
 * @property int $total_xp
 * @property Carbon $issued_at
 */
final class AcademyCertificate extends BaseEnterpriseModel
{
    protected $table = 'academy_certificates';

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AcademyLearningTrack, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(AcademyLearningTrack::class, 'track_id');
    }
}
