<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The certificate issued when a course is completed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $course_id
 * @property string $employee_id
 * @property string $certificate_number
 * @property Carbon $issued_on
 * @property Carbon|null $expires_on
 */
final class LmsCertificate extends BaseEnterpriseModel
{
    protected $table = 'tl_certificates';

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    /**
     * @return BelongsTo<LmsCourse, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
