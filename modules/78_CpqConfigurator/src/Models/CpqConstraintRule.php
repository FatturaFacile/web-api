<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A deterministic rule over the selection. While `condition_expression` is true (empty = always):
 *  - `exclude_options` disables the `target_options` of `target_characteristic` and explains why;
 *  - `force_value` makes `forced_value` the value of the target (an automatic inclusion, e.g. the safety kit for CH);
 *  - `hide` hides the target characteristic;
 *  - `limit_range` narrows the numeric range of the target (`params.min`, `params.max`);
 *  - `validate` reports the `message` as a violation of `severity`;
 *  - `decision_table` takes the first row of `params.rows` ({when, outputs, message?}) whose `when` is true and publishes
 *    its `outputs` as derived variables (and, for a characteristic code, as forced values).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $code
 * @property string $rule_type
 * @property string|null $condition_expression
 * @property string|null $target_characteristic
 * @property list<string>|null $target_options
 * @property mixed $forced_value
 * @property array<string, mixed>|null $params
 * @property array<string, string>|null $message
 * @property string $severity
 * @property int $priority
 * @property bool $is_active
 */
final class CpqConstraintRule extends BaseEnterpriseModel
{
    public const string ExcludeOptions = 'exclude_options';

    public const string ForceValue = 'force_value';

    public const string Hide = 'hide';

    public const string LimitRange = 'limit_range';

    public const string Validate = 'validate';

    public const string DecisionTable = 'decision_table';

    /** @var list<string> */
    public const array Types = [self::ExcludeOptions, self::ForceValue, self::Hide, self::LimitRange, self::Validate, self::DecisionTable];

    /** @var list<string> */
    public const array Severities = ['error', 'warning', 'info'];

    protected $table = 'cpq_constraint_rules';

    protected function casts(): array
    {
        return [
            'target_options' => 'array',
            'forced_value' => 'array',
            'params' => 'array',
            'message' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
