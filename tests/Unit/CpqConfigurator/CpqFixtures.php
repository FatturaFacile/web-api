<?php

declare(strict_types=1);

/**
 * Hand-written definitions for the unit tests of the CPQ engines: they run without a database, on the plain arrays the
 * ModelDefinitionLoader produces.
 */

/**
 * @param  list<string>  $options
 * @return array<string, mixed>
 */
function cpqList(string $code, array $options, ?string $default = null, bool $required = true): array
{
    return cpqCharacteristic($code, 'list', [
        'required' => $required,
        'options' => array_map(static fn (string $option): array => [
            'id' => $code.$option, 'code' => $option, 'label' => ['en' => $option], 'description' => null, 'part_number_code' => null,
            'default' => $option === $default, 'active' => true, 'properties' => ['rank' => strlen($option)], 'visual' => null,
        ], $options),
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cpqCharacteristic(string $code, string $type, array $extra = []): array
{
    return $extra + [
        'id' => $code, 'code' => $code, 'step' => null, 'type' => $type, 'label' => ['en' => ucfirst($code)], 'help' => null, 'unit' => null,
        'min' => null, 'max' => null, 'step_size' => null, 'default' => null, 'required' => true, 'part_number' => null, 'visual' => null, 'options' => [],
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cpqRule(string $code, string $type, ?string $condition, array $extra = []): array
{
    return $extra + [
        'id' => $code, 'code' => $code, 'type' => $type, 'condition' => $condition, 'target' => null, 'target_options' => [], 'forced_value' => null,
        'params' => [], 'message' => ['en' => $code.' message'], 'severity' => 'error', 'priority' => 100, 'active' => true,
    ];
}

/**
 * @param  list<array<string, mixed>>  $characteristics
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function cpqDefinition(array $characteristics, array $rules = []): array
{
    return [
        'id' => 'model-1', 'code' => 'M', 'version' => 1, 'status' => 'published', 'currency' => 'EUR', 'name' => ['en' => 'Model'], 'description' => null,
        'base_price' => 0.0, 'base_cost' => 0.0, 'base_lead_time_days' => 0, 'target_margin_percent' => null, 'max_discount_percent' => 100.0,
        'part_number_prefix' => 'M', 'steps' => [], 'visual_base' => null, 'proposal_texts' => null,
        'characteristics' => array_column($characteristics, null, 'code'), 'rules' => $rules, 'bom' => [], 'routing' => [], 'pricing' => [],
    ];
}

/**
 * @param  array<string, mixed>  $solution
 * @return list<string>
 */
function cpqViolationCodes(array $solution): array
{
    return array_values(array_unique(array_column($solution['violations'], 'code')));
}
