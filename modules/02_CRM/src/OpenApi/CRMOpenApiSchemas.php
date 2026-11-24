<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

final class CRMOpenApiSchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return [
            'CrmLead' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'first_name' => ['type' => 'string'],
                    'last_name' => ['type' => 'string'],
                    'company_name' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'phone' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['new', 'contacting', 'engaged', 'qualified', 'disqualified', 'converted', 'archived']],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'rating' => ['type' => 'string', 'enum' => ['hot', 'warm', 'cold']],
                    'estimated_budget' => ['type' => 'number', 'format' => 'float'],
                ],
                'required' => ['id', 'status', 'score', 'rating'],
            ],
            'CrmOpportunity' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'opportunity_number' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'amount' => ['type' => 'number', 'format' => 'float'],
                    'expected_revenue' => ['type' => 'number', 'format' => 'float'],
                    'currency' => ['type' => 'string', 'example' => 'EUR'],
                    'probability' => ['type' => 'number', 'format' => 'float'],
                    'forecast_category' => ['type' => 'string', 'enum' => ['pipeline', 'best_case', 'commit', 'closed', 'omitted']],
                    'expected_close_date' => ['type' => 'string', 'format' => 'date'],
                    'status' => ['type' => 'string', 'enum' => ['open', 'won', 'lost', 'cancelled']],
                    'approval_status' => ['type' => 'string', 'enum' => ['not_required', 'pending_approval', 'approved', 'rejected']],
                ],
                'required' => ['id', 'opportunity_number', 'name', 'amount', 'currency', 'status'],
            ],
            'CrmAccount' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'account_number' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'tier' => ['type' => 'string', 'enum' => ['strategic', 'enterprise', 'mid_market', 'smb', 'standard']],
                    'status' => ['type' => 'string', 'enum' => ['prospect', 'active', 'inactive', 'churned']],
                    'customer_health_index' => ['type' => 'number', 'format' => 'float'],
                    'churn_risk' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                ],
                'required' => ['id', 'account_number', 'name', 'tier', 'status'],
            ],
            'CrmActivity' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'subject' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => ['task', 'call', 'meeting', 'note', 'email', 'follow_up']],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed', 'cancelled']],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                    'due_date' => ['type' => 'string', 'format' => 'date-time'],
                    'completed_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => ['id', 'subject', 'type', 'status', 'priority'],
            ],
            'CrmForecast' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'period' => ['type' => 'string'],
                    'pipeline_amount' => ['type' => 'number', 'format' => 'float'],
                    'best_case_amount' => ['type' => 'number', 'format' => 'float'],
                    'commit_amount' => ['type' => 'number', 'format' => 'float'],
                    'closed_amount' => ['type' => 'number', 'format' => 'float'],
                    'weighted_amount' => ['type' => 'number', 'format' => 'float'],
                    'attainment_percent' => ['type' => 'number', 'format' => 'float'],
                ],
                'required' => ['id', 'period'],
            ],
            'CrmCampaign' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'code' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'budgeted_cost' => ['type' => 'number', 'format' => 'float'],
                    'actual_cost' => ['type' => 'number', 'format' => 'float'],
                    'roi_percentage' => ['type' => 'number', 'format' => 'float'],
                ],
                'required' => ['id', 'code', 'name'],
            ],
        ];
    }
}
