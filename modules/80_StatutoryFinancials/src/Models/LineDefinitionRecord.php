<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\StatutoryFinancials\Domain\Statement\LineDefinition;

/**
 * A line of the official statement schemes. Rows without a tenant are the global catalogue.
 *
 * @property string $code
 * @property string $family
 * @property string $statement
 * @property string $variant
 * @property string|null $parent_code
 * @property int $sign
 * @property string $side
 * @property int $sort_order
 * @property list<string> $schemes
 * @property list<array{0: string, 1: int}>|null $formula
 * @property string|null $cf_group
 * @property string|null $xbrl_element
 * @property bool $is_rounding
 * @property array<string, string> $labels
 */
final class LineDefinitionRecord extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'sfs_line_definitions';

    protected function casts(): array
    {
        return [
            'sign' => 'integer',
            'sort_order' => 'integer',
            'schemes' => 'array',
            'formula' => 'array',
            'is_rounding' => 'boolean',
            'labels' => 'array',
        ];
    }

    public function toDefinition(): LineDefinition
    {
        return new LineDefinition(
            code: $this->code,
            family: $this->family,
            statement: $this->statement,
            variant: $this->variant,
            parentCode: $this->parent_code,
            sign: $this->sign,
            side: $this->side,
            sortOrder: $this->sort_order,
            schemes: $this->schemes,
            formula: $this->formula,
            cfGroup: $this->cf_group,
            xbrlElement: $this->xbrl_element,
            isRounding: $this->is_rounding,
            labels: $this->labels,
        );
    }
}
