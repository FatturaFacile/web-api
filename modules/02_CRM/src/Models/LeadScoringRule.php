<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A tenant-defined lead scoring rule.
 *
 * @property string $id
 * @property string|null $code
 * @property string $name
 * @property string $rule_type
 * @property string|null $category
 * @property string|null $field
 * @property string|null $event_type
 * @property string|null $operator
 * @property mixed $value
 * @property string|null $activity_type
 * @property string|null $activity_status
 * @property int $points
 * @property int|null $max_points
 * @property int|null $max_applications
 * @property int|null $decay_days
 * @property int $sequence
 * @property bool $is_active
 */
final class LeadScoringRule extends BaseEnterpriseModel
{
    public const string TypeField = 'field';

    public const string TypeActivity = 'activity';

    public const array Fields = ['title', 'email', 'phone', 'company_name', 'website', 'country', 'estimated_budget', 'status', 'lead_source_code', 'campaign_id'];

    public const array Operators = ['filled', 'empty', 'equals', 'not_equals', 'contains', 'in', 'gte', 'lte', 'business_email'];

    public const array CATEGORIES = ['demographic', 'behavioral'];

    public const array OPERATORS = ['eq', 'in', 'gte', 'lte', 'between', 'contains', 'exists'];

    public const array DEMOGRAPHIC_FIELDS = [
        'industry',
        'annual_revenue_estimate',
        'employee_count',
        'state_region',
        'country',
        'product_line',
        'title',
        'estimated_budget',
        'company_name',
        'email',
        'email_domain',
        'phone',
        'website',
        'vat_number',
    ];

    protected $table = 'crm_lead_scoring_rules';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rule_type' => 'field',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'points' => 'integer',
            'max_points' => 'integer',
            'max_applications' => 'integer',
            'decay_days' => 'integer',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
