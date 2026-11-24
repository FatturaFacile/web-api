<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

/**
 * API representation of an analytical center.
 */
final class CenterResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'parent_id', 'code', 'name', 'name_translations', 'center_type', 'category', 'path', 'depth', 'manager_user_id', 'manager_name', 'is_active', 'valid_from', 'valid_to', 'default_profit_center_id', 'default_project_id', 'default_product_line_id', 'default_cost_element_type', 'metadata'];
    }
}
