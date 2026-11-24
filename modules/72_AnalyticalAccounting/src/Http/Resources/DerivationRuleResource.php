<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

/**
 * API representation of an analytical derivation rule.
 */
final class DerivationRuleResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'name', 'priority', 'is_active', 'valid_from', 'valid_to', 'accounting_cause_id', 'journal_type', 'product_id', 'product_category_id', 'account_id', 'counterparty_type', 'counterparty_id', 'cost_center_id', 'profit_center_id', 'project_id', 'product_line_id', 'cost_element_type'];
    }
}
