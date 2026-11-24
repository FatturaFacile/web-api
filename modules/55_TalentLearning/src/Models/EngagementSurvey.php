<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An engagement survey. With is_anonymous the responses never keep the respondent, and results are only shown for
 * groups of at least min_group_size responses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property bool $is_anonymous
 * @property int $min_group_size
 * @property Carbon|null $opens_on
 * @property Carbon|null $closes_on
 */
final class EngagementSurvey extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Open = 'open';

    public const string Closed = 'closed';

    protected $table = 'tl_surveys';

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'min_group_size' => 'integer', 'opens_on' => 'date', 'closes_on' => 'date'];
    }

    /**
     * @return HasMany<EngagementQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EngagementQuestion::class, 'survey_id')->orderBy('position');
    }
}
