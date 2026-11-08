<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A tenant-defined lead scoring rule. Field rules add points when a lead attribute satisfies a condition; activity
 * rules add points for each logged activity of a type (optionally capped), so scoring reflects engagement and not
 * only the data typed at capture time.
 *
 * @property string $id
 * @property string $name
 * @property string $rule_type
 * @property string|null $field
 * @property string|null $operator
 * @property string|null $value
 * @property string|null $activity_type
 * @property string|null $activity_status
 * @property int $points
 * @property int|null $max_points
 * @property int $sequence
 * @property bool $is_active
 */
final class LeadScoringRule extends BaseEnterpriseModel
{
    public const string TypeField = 'field';

    public const string TypeActivity = 'activity';

    /**
     * Lead attributes a field rule may test.
     */
    public const array Fields = ['title', 'email', 'phone', 'company_name', 'website', 'country', 'estimated_budget', 'status', 'lead_source_code', 'campaign_id'];

    /**
     * Comparison operators of field rules.
     */
    public const array Operators = ['filled', 'empty', 'equals', 'not_equals', 'contains', 'in', 'gte', 'lte', 'business_email'];

    protected $table = 'crm_lead_scoring_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'max_points' => 'integer',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
