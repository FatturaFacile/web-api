<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A risk of the register, scored likelihood x impact on a 1-5 scale. The inherent score is the risk before controls,
 * the residual score the risk left after treatment. The rating (low, medium, high, critical) comes from the score.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property int $likelihood
 * @property int $impact
 * @property int $inherent_score
 * @property int|null $residual_likelihood
 * @property int|null $residual_impact
 * @property int|null $residual_score
 * @property string|null $treatment
 * @property string|null $treatment_notes
 * @property string|null $owner_id
 * @property string $status
 * @property Carbon|null $next_review_date
 * @property Carbon|null $closed_at
 */
final class GovRisk extends BaseEnterpriseModel
{
    public const array Categories = ['strategic', 'operational', 'financial', 'compliance', 'it_security', 'legal', 'reputational', 'environmental'];

    public const array Treatments = ['mitigate', 'accept', 'transfer', 'avoid'];

    public const array Statuses = ['identified', 'assessed', 'treating', 'accepted', 'closed'];

    public const array Ratings = ['low', 'medium', 'high', 'critical'];

    /** Statuses of a risk that is still managed. */
    public const array LiveStatuses = ['identified', 'assessed', 'treating', 'accepted'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'identified' => ['assessed', 'closed'],
        'assessed' => ['treating', 'accepted', 'closed'],
        'treating' => ['assessed', 'accepted', 'closed'],
        'accepted' => ['treating', 'closed'],
        'closed' => ['assessed'],
    ];

    protected $table = 'gov_risks';

    protected function casts(): array
    {
        return [
            'likelihood' => 'integer',
            'impact' => 'integer',
            'inherent_score' => 'integer',
            'residual_likelihood' => 'integer',
            'residual_impact' => 'integer',
            'residual_score' => 'integer',
            'next_review_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Rating of a score: 1-4 low, 5-9 medium, 10-14 high, 15-25 critical.
     */
    public static function ratingOf(int $score): string
    {
        return match (true) {
            $score >= 15 => 'critical',
            $score >= 10 => 'high',
            $score >= 5 => 'medium',
            default => 'low',
        };
    }

    /**
     * The residual score, or the inherent one while the risk has not been reassessed after treatment.
     */
    public function effectiveScore(): int
    {
        return $this->residual_score ?? $this->inherent_score;
    }

    public function isReviewOverdue(): bool
    {
        return in_array($this->status, self::LiveStatuses, true) && $this->next_review_date !== null && $this->next_review_date->endOfDay()->isPast();
    }

    /**
     * @return HasMany<GovRiskControl, $this>
     */
    public function riskControls(): HasMany
    {
        return $this->hasMany(GovRiskControl::class, 'risk_id');
    }

    /**
     * @return BelongsToMany<GovControl, $this>
     */
    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(GovControl::class, 'gov_risk_controls', 'risk_id', 'control_id')->wherePivotNull('deleted_at');
    }
}
