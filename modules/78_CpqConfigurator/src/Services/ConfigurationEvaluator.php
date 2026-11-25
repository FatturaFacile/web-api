<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Support\Localized;

/**
 * The live state of a guided configuration, in one call: constraints, automatic inclusions, blocked options with the
 * reason, resolved super-BOM and routing, cost, price, margin, lead time, the layers of the visual preview, the
 * configuration hash and the part number the configuration would get (the existing one when it is already known).
 * Everything the screen shows comes from here, in the language of the request; nothing is saved.
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 */
final class ConfigurationEvaluator
{
    public function __construct(
        private readonly ModelDefinitionLoader $loader,
        private readonly ConstraintSolver $solver,
        private readonly PricingEngine $pricing,
        private readonly ConfigurationHasher $hasher,
        private readonly PartNumberGenerator $partNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $selections
     * @return array<string, mixed>
     */
    public function evaluateModel(CpqModel $model, array $selections, float $quantity = 1.0, float $discountPercent = 0.0, ?string $locale = null): array
    {
        $result = $this->evaluate($this->loader->load($model), $selections, $quantity, $discountPercent, $locale);
        $existing = CpqConfiguredInstance::query()->where('model_id', $model->id)->where('configuration_hash', $result['configuration_hash'])->first();

        $result['deduplication'] = [
            'hash' => $result['configuration_hash'],
            'existing' => $existing instanceof CpqConfiguredInstance,
            'part_number' => $existing?->part_number,
            'instance_id' => $existing?->id,
        ];

        return $result;
    }

    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $selections
     * @return array<string, mixed>
     */
    public function evaluate(array $definition, array $selections, float $quantity = 1.0, float $discountPercent = 0.0, ?string $locale = null): array
    {
        $solution = $this->solver->solve($definition, $selections, $locale);
        $priced = $this->pricing->price($definition, $solution['variables'], max($quantity, 0.0001), $discountPercent);
        $canonical = $this->hasher->canonical($definition, $solution['values'], $solution['visible']);

        return [
            'model' => ['id' => $definition['id'], 'code' => $definition['code'], 'name' => Localized::pick($definition['name'], $locale), 'version' => $definition['version'], 'currency' => $definition['currency']],
            'valid' => $solution['valid'],
            'complete' => $solution['complete'],
            'missing' => $solution['missing'],
            'values' => $solution['values'],
            'violations' => $this->violations($solution['violations'], $definition),
            'auto_included' => $this->autoIncluded($solution['auto_included'], $definition, $locale),
            'steps' => $this->steps($definition, $solution, $locale),
            'characteristics' => $this->characteristics($definition, $solution, $locale),
            'bom' => $priced['bom'],
            'routing' => $priced['routing'],
            'pricing' => ['components' => $priced['components'], 'errors' => $priced['errors']],
            'totals' => $priced['totals'],
            'visual' => $this->visual($definition, $solution, $locale),
            'configuration_hash' => $this->hasher->hash($canonical),
            'canonical' => $canonical,
            'part_number_preview' => $this->partNumbers->stem($definition, $solution['values'], $solution['visible']).'-####',
            'solution' => ['visible' => $solution['visible'], 'derived' => $solution['derived']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @param  Definition  $definition
     * @return list<array<string, mixed>>
     */
    private function violations(array $violations, array $definition): array
    {
        return array_map(static function (array $violation) use ($definition): array {
            $characteristic = $violation['characteristic'];
            $violation['characteristic_label'] = $characteristic !== null && isset($definition['characteristics'][$characteristic])
                ? Localized::pick($definition['characteristics'][$characteristic]['label'])
                : null;

            return $violation;
        }, $violations);
    }

    /**
     * @param  list<array<string, mixed>>  $auto
     * @param  Definition  $definition
     * @return list<array<string, mixed>>
     */
    private function autoIncluded(array $auto, array $definition, ?string $locale): array
    {
        return array_map(function (array $entry) use ($definition, $locale): array {
            $characteristic = $definition['characteristics'][$entry['characteristic']];
            $entry['characteristic_label'] = Localized::pick($characteristic['label'], $locale);
            $entry['value_label'] = $this->valueLabel($characteristic, $entry['value'], $locale);

            return $entry;
        }, $auto);
    }

    /**
     * @param  array<string, mixed>  $characteristic
     */
    private function valueLabel(array $characteristic, mixed $value, ?string $locale): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($characteristic['type'] === CpqCharacteristic::TypeList) {
            foreach ($characteristic['options'] as $option) {
                if ($option['code'] === $value) {
                    return Localized::pick($option['label'], $locale);
                }
            }
        }

        return is_bool($value) ? null : (string) $value;
    }

    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $solution
     * @return list<array<string, mixed>>
     */
    private function steps(array $definition, array $solution, ?string $locale): array
    {
        $steps = $definition['steps'] !== [] ? $definition['steps'] : [['code' => 'configuration', 'title' => null, 'description' => null]];
        $known = array_column($steps, 'code');
        $result = [];

        foreach ($steps as $index => $step) {
            $codes = [];

            foreach ($definition['characteristics'] as $code => $characteristic) {
                $stepCode = $characteristic['step'] ?? null;
                $belongs = $stepCode === $step['code'] || (($stepCode === null || ! in_array($stepCode, $known, true)) && $index === 0);

                if ($belongs) {
                    $codes[] = $code;
                }
            }

            $hasError = false;
            $incomplete = false;

            foreach ($codes as $code) {
                $incomplete = $incomplete || in_array($code, $solution['missing'], true);
                $hasError = $hasError || array_filter($solution['violations'], static fn (array $violation): bool => $violation['characteristic'] === $code && $violation['severity'] === 'error') !== [];
            }

            $result[] = [
                'code' => $step['code'],
                'title' => isset($step['title']) ? Localized::pick($step['title'], $locale) : null,
                'description' => isset($step['description']) ? Localized::pick($step['description'], $locale) : null,
                'characteristics' => $codes,
                'status' => $hasError ? 'error' : ($incomplete ? 'incomplete' : 'complete'),
            ];
        }

        return $result;
    }

    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $solution
     * @return list<array<string, mixed>>
     */
    private function characteristics(array $definition, array $solution, ?string $locale): array
    {
        $forced = [];

        foreach ($solution['auto_included'] as $entry) {
            $forced[$entry['characteristic']] = $entry;
        }

        $result = [];

        foreach ($definition['characteristics'] as $code => $characteristic) {
            $value = $solution['values'][$code] ?? null;
            $limit = $solution['limits'][$code];
            $options = [];

            foreach ($characteristic['options'] as $option) {
                $reasons = $solution['disabled'][$code][$option['code']] ?? [];
                $options[] = [
                    'code' => $option['code'],
                    'label' => Localized::pick($option['label'], $locale),
                    'description' => Localized::pick($option['description'], $locale) ?: null,
                    'disabled' => ! $option['active'] || $reasons !== [],
                    'reasons' => array_values(array_filter($reasons)),
                    'selected' => $value === $option['code'],
                    'properties' => $option['properties'],
                ];
            }

            $result[] = [
                'code' => $code,
                'step' => $characteristic['step'],
                'type' => $characteristic['type'],
                'label' => Localized::pick($characteristic['label'], $locale),
                'help' => Localized::pick($characteristic['help'], $locale) ?: null,
                'unit' => $characteristic['unit'],
                'required' => $characteristic['required'],
                'visible' => $solution['visible'][$code],
                'value' => $value,
                'min' => $limit['min'],
                'max' => $limit['max'],
                'step_size' => $limit['step'],
                'forced' => isset($forced[$code]),
                'forced_message' => $forced[$code]['message'] ?? null,
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * Layers of the 2D/3D preview: the base of the model plus a layer for every selected option (or boolean switched
     * on) that has a `visual` description, ordered by z. Automatically included layers are flagged.
     *
     * @param  Definition  $definition
     * @param  array<string, mixed>  $solution
     * @return array<string, mixed>
     */
    private function visual(array $definition, array $solution, ?string $locale): array
    {
        $auto = array_column($solution['auto_included'], 'characteristic');
        $layers = [];

        foreach ($definition['characteristics'] as $code => $characteristic) {
            if (! $solution['visible'][$code]) {
                continue;
            }

            $value = $solution['values'][$code] ?? null;
            $spec = null;
            $label = null;

            if ($characteristic['type'] === CpqCharacteristic::TypeList && $value !== null) {
                foreach ($characteristic['options'] as $option) {
                    if ($option['code'] === $value && is_array($option['visual'])) {
                        $spec = $option['visual'];
                        $label = Localized::pick($option['label'], $locale);
                    }
                }
            } elseif ($characteristic['type'] === CpqCharacteristic::TypeBoolean && $value === true && is_array($characteristic['visual'] ?? null)) {
                $spec = $characteristic['visual'];
                $label = Localized::pick($characteristic['label'], $locale);
            }

            if ($spec === null) {
                continue;
            }

            $layers[] = [
                'id' => $code,
                'characteristic' => $code,
                'option' => is_string($value) ? $value : null,
                'label' => $label,
                'kind' => $spec['layer'] ?? 'accessory',
                'shape' => $spec['shape'] ?? 'panel',
                'color' => $spec['color'] ?? '#8c8c8c',
                'z' => (int) ($spec['z'] ?? 10),
                'slot' => $spec['slot'] ?? null,
                'auto_included' => in_array($code, $auto, true),
                'visible' => true,
            ];
        }

        usort($layers, static fn (array $a, array $b): int => [$a['z'], $a['id']] <=> [$b['z'], $b['id']]);

        $base = $definition['visual_base'] ?? ['shape' => 'body', 'color' => '#bfc5cc'];
        $scale = $base['scale'] ?? null;

        if (is_array($scale) && isset($scale['characteristic'], $definition['characteristics'][$scale['characteristic']])) {
            $limit = $solution['limits'][$scale['characteristic']];
            $value = $solution['values'][$scale['characteristic']] ?? null;
            $span = ($limit['max'] ?? 0) - ($limit['min'] ?? 0);
            $ratio = $value !== null && $span > 0 ? min(1, max(0, ((float) $value - (float) $limit['min']) / $span)) : 0.5;
            $base['scale_factor'] = round((float) ($scale['min_factor'] ?? 0.8) + ($ratio * ((float) ($scale['max_factor'] ?? 1.2) - (float) ($scale['min_factor'] ?? 0.8))), 3);
        }

        if (isset($base['label'])) {
            $base['label'] = Localized::pick($base['label'], $locale);
        }

        return ['base' => $base, 'layers' => $layers, 'views' => ['plan', 'elevation', 'isometric']];
    }
}
