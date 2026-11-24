<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Configurator\Models\ConfiguratorRule;

/**
 * A rule over the selected options, on creation (POST) or change (PUT). Whether it creates a cycle or a conflict with
 * the other rules is checked by the service when it is saved.
 */
final class RuleRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $targeted = 'required_if:type,'.implode(',', [ConfiguratorRule::Requires, ConfiguratorRule::Excludes, ConfiguratorRule::Default]);

        return [
            'name' => [$presence, 'string', 'max:190'],
            'type' => [$presence, Rule::in(ConfiguratorRule::Types)],
            'match' => ['sometimes', Rule::in(['all', 'any'])],
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.type' => ['required', Rule::in(['option', 'range'])],
            'conditions.*.option' => ['required_if:conditions.*.type,option', 'nullable', 'string', 'max:40'],
            'conditions.*.negate' => ['sometimes', 'boolean'],
            'conditions.*.group' => ['required_if:conditions.*.type,range', 'nullable', 'string', 'max:40'],
            'conditions.*.operator' => ['required_if:conditions.*.type,range', 'nullable', Rule::in(ConfiguratorRule::Operators)],
            'conditions.*.value' => ['required_if:conditions.*.type,range', 'nullable', 'numeric'],
            'target_options' => [$targeted, 'nullable', 'array', 'max:50'],
            'target_options.*' => ['string', 'max:40', 'distinct'],
            'price_effect_type' => ['required_if:type,price_rule', 'nullable', Rule::in(ConfiguratorRule::PriceEffects)],
            'price_effect_value' => ['required_if:type,price_rule', 'nullable', 'numeric', 'min:-99999999999', 'max:99999999999'],
            'message' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The payload to store: a price rule has no targets, the other rules have no price effect, and every condition
     * keeps only the keys of its own kind. A change that names no type keeps the type of the rule.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $currentType = null): array
    {
        $data = $this->validated();
        $type = $data['type'] ?? $currentType;

        if (isset($data['conditions'])) {
            $data['conditions'] = array_values(array_map(static fn (array $condition): array => ($condition['type'] ?? 'option') === 'range'
                ? ['type' => 'range', 'group' => (string) $condition['group'], 'operator' => (string) $condition['operator'], 'value' => (string) $condition['value']]
                : ['type' => 'option', 'option' => (string) $condition['option'], 'negate' => (bool) ($condition['negate'] ?? false)], $data['conditions']));
        }

        if ($type === ConfiguratorRule::PriceRule) {
            $data['target_options'] = null;
        } elseif ($type !== null) {
            $data['price_effect_type'] = null;
            $data['price_effect_value'] = null;
        }

        return $data;
    }
}
