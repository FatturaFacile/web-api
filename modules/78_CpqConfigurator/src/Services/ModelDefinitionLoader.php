<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Models\CpqRoutingMapping;
use Modules\CpqConfigurator\Models\CpqSuperBomMapping;

/**
 * Reads a model with everything it owns into one plain array, the "definition" the solver and the pricing engine work
 * on. The engines never touch the database, so they can be tested with a hand-written definition.
 *
 * @phpstan-type Definition array{
 *     id: string, code: string, version: int, status: string, currency: string, name: array<string, string>, description: array<string, string>|null,
 *     base_price: float, base_cost: float, base_lead_time_days: int, target_margin_percent: float|null, max_discount_percent: float,
 *     part_number_prefix: string, steps: list<array<string, mixed>>, visual_base: array<string, mixed>|null, proposal_texts: array<string, mixed>|null,
 *     characteristics: array<string, array<string, mixed>>, rules: list<array<string, mixed>>, bom: list<array<string, mixed>>,
 *     routing: list<array<string, mixed>>, pricing: list<array<string, mixed>>
 * }
 */
final class ModelDefinitionLoader
{
    /**
     * @return Definition
     */
    public function load(CpqModel $model): array
    {
        $model->loadMissing(['characteristics.options', 'rules', 'bomMappings', 'routingMappings', 'pricingFormulas']);

        $characteristics = [];

        foreach ($model->characteristics as $characteristic) {
            $characteristics[$characteristic->code] = $this->characteristic($characteristic);
        }

        return [
            'id' => (string) $model->id,
            'code' => $model->code,
            'version' => $model->version,
            'status' => $model->status,
            'currency' => $model->currency,
            'name' => $model->name,
            'description' => $model->description,
            'base_price' => (float) $model->base_price,
            'base_cost' => (float) $model->base_cost,
            'base_lead_time_days' => $model->base_lead_time_days,
            'target_margin_percent' => $model->target_margin_percent === null ? null : (float) $model->target_margin_percent,
            'max_discount_percent' => (float) $model->max_discount_percent,
            'part_number_prefix' => $model->part_number_prefix,
            'steps' => $model->steps ?? [],
            'visual_base' => $model->visual_base,
            'proposal_texts' => $model->proposal_texts,
            'characteristics' => $characteristics,
            'rules' => $model->rules->map(fn (CpqConstraintRule $rule): array => $this->rule($rule))->all(),
            'bom' => $model->bomMappings->map(fn (CpqSuperBomMapping $mapping): array => $this->bom($mapping))->all(),
            'routing' => $model->routingMappings->map(fn (CpqRoutingMapping $mapping): array => $this->routing($mapping))->all(),
            'pricing' => $model->pricingFormulas->map(fn (CpqPricingFormula $formula): array => $this->pricing($formula))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characteristic(CpqCharacteristic $characteristic): array
    {
        return [
            'id' => (string) $characteristic->id,
            'code' => $characteristic->code,
            'step' => $characteristic->step_code,
            'type' => $characteristic->type,
            'label' => $characteristic->label,
            'help' => $characteristic->help,
            'unit' => $characteristic->unit,
            'min' => $characteristic->range_min === null ? null : (float) $characteristic->range_min,
            'max' => $characteristic->range_max === null ? null : (float) $characteristic->range_max,
            'step_size' => $characteristic->range_step === null ? null : (float) $characteristic->range_step,
            'default' => $characteristic->default_value,
            'required' => $characteristic->is_required,
            'part_number' => $characteristic->part_number,
            'visual' => $characteristic->visual,
            'options' => $characteristic->options->map(fn (CpqCharacteristicOption $option): array => [
                'id' => (string) $option->id,
                'code' => $option->code,
                'label' => $option->label,
                'description' => $option->description,
                'part_number_code' => $option->part_number_code,
                'default' => $option->is_default,
                'active' => $option->is_active,
                'properties' => $option->properties ?? [],
                'visual' => $option->visual,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(CpqConstraintRule $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'code' => $rule->code,
            'type' => $rule->rule_type,
            'condition' => $rule->condition_expression,
            'target' => $rule->target_characteristic,
            'target_options' => $rule->target_options ?? [],
            'forced_value' => $rule->forced_value,
            'params' => $rule->params ?? [],
            'message' => $rule->message,
            'severity' => $rule->severity,
            'priority' => $rule->priority,
            'active' => $rule->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bom(CpqSuperBomMapping $mapping): array
    {
        return [
            'id' => (string) $mapping->id,
            'code' => $mapping->code,
            'item_code' => $mapping->item_code,
            'item_name' => $mapping->item_name,
            'uom' => $mapping->uom,
            'condition' => $mapping->condition_expression,
            'quantity_formula' => $mapping->quantity_formula,
            'unit_cost' => (float) $mapping->unit_cost,
            'unit_cost_formula' => $mapping->unit_cost_formula,
            'lead_time_days' => $mapping->lead_time_days,
            'auto_include_message' => $mapping->auto_include_message,
            'active' => $mapping->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routing(CpqRoutingMapping $mapping): array
    {
        return [
            'id' => (string) $mapping->id,
            'code' => $mapping->code,
            'operation_name' => $mapping->operation_name,
            'work_center' => $mapping->work_center,
            'condition' => $mapping->condition_expression,
            'setup_hours_formula' => $mapping->setup_hours_formula,
            'run_hours_formula' => $mapping->run_hours_formula,
            'hourly_rate' => (float) $mapping->hourly_rate,
            'active' => $mapping->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(CpqPricingFormula $formula): array
    {
        return [
            'id' => (string) $formula->id,
            'code' => $formula->code,
            'label' => $formula->label,
            'effect' => $formula->effect,
            'condition' => $formula->condition_expression,
            'formula' => $formula->formula,
            'active' => $formula->is_active,
        ];
    }
}
