<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course of the learning catalogue. A mandatory course applies to every active employee; validity_months makes the
 * certificate expire (recertification).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property string $delivery
 * @property string $duration_hours
 * @property bool $is_mandatory
 * @property int|null $validity_months
 * @property int|null $pass_score
 * @property string $status
 */
final class LmsCourse extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Published = 'published';

    public const string Archived = 'archived';

    /** @var list<string> */
    public const array Deliveries = ['online', 'classroom', 'blended'];

    protected $table = 'tl_courses';

    protected function casts(): array
    {
        return ['duration_hours' => 'decimal:1', 'is_mandatory' => 'boolean', 'validity_months' => 'integer', 'pass_score' => 'integer'];
    }

    /**
     * @return HasMany<LmsEnrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(LmsEnrolment::class, 'course_id');
    }
}
