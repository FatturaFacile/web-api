<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class LeadScoringRule extends BaseEnterpriseModel
{
    public const array CATEGORIES = ['demographic', 'behavioral'];

    public const array OPERATORS = ['eq', 'in', 'gte', 'lte', 'between', 'contains', 'exists'];

    /**
     * Lead attributes a demographic rule may evaluate.
     */
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

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'points' => 'integer',
            'max_applications' => 'integer',
            'decay_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
