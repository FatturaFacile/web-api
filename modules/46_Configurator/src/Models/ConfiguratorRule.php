<?php

declare(strict_types=1);

namespace Modules\Configurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A rule over the selected options. When its conditions hold (all of them, or any, as `match` says):
 * - requires: every target option must be selected;
 * - excludes: no target option may be selected;
 * - default: the target options are selected for the groups that have no selection yet;
 * - price_rule: the price effect (a fixed amount or a percentage of the component subtotal) is added to the price.
 *
 * A condition is either `{"type":"option","option":"CODE","negate":false}` or
 * `{"type":"range","group":"CODE","operator":"gte","value":"4"}`.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $name
 * @property string $type
 * @property string $match
 * @property list<array<string, mixed>>|null $conditions
 * @property list<string>|null $target_options
 * @property string|null $price_effect_type
 * @property string|null $price_effect_value
 * @property string|null $message
 * @property int $priority
 * @property bool $is_active
 */
final class ConfiguratorRule extends BaseEnterpriseModel
{
    public const string Requires = 'requires';

    public const string Excludes = 'excludes';

    public const string Default = 'default';

    public const string PriceRule = 'price_rule';

    /** @var list<string> */
    public const array Types = [self::Requires, self::Excludes, self::Default, self::PriceRule];

    /** @var list<string> */
    public const array Operators = ['eq', 'gt', 'gte', 'lt', 'lte'];

    /** @var list<string> */
    public const array PriceEffects = ['amount', 'percent'];

    protected $table = 'cfg_rules';

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'target_options' => 'array',
            'price_effect_value' => 'decimal:4',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
