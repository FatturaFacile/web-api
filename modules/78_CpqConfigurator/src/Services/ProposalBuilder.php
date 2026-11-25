<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Support\Localized;

/**
 * The structured technical-commercial dossier of a configuration: product and variant, technical specification by step,
 * selected options and automatic inclusions, the layers of the preview, dimensional and installation notes, the
 * commercial conditions (price, quantity, lead time, validity, warranty, terms) and, only for who may see the costs,
 * the industrial breakdown. The commercial texts are the ones written in the model (seeded), in the requested language.
 */
final class ProposalBuilder
{
    public function __construct(private readonly ConfigurationEvaluator $evaluator, private readonly ModelDefinitionLoader $loader) {}

    /**
     * A dossier for a configuration that is not saved (a preview).
     *
     * @param  array<string, mixed>  $selections
     * @return array<string, mixed>
     */
    public function forSelections(CpqModel $model, array $selections, float $quantity, float $discountPercent, bool $withBreakdown, ?string $locale = null, ?string $customer = null): array
    {
        $evaluation = $this->evaluator->evaluateModel($model, $selections, $quantity, $discountPercent, $locale);

        return $this->build($model, $evaluation, null, $withBreakdown, $locale, $customer);
    }

    /**
     * The dossier of a confirmed instance. It is rebuilt in the requested language from the current rules while the
     * model is still at the version of the instance, and from the stored snapshot once the rules have changed.
     *
     * @return array<string, mixed>
     */
    public function forInstance(CpqConfiguredInstance $instance, float $quantity, float $discountPercent, bool $withBreakdown, ?string $locale = null, ?string $customer = null): array
    {
        $model = CpqModel::query()->findOrFail($instance->model_id);

        if ($model->version === $instance->model_version) {
            $evaluation = $this->evaluator->evaluate($this->loader->load($model), $instance->configuration['values'] ?? [], $quantity, $discountPercent, $locale);
        } else {
            $evaluation = $instance->snapshot;
            $evaluation['totals']['quantity'] = $quantity;
            $evaluation['totals']['total_selling_price'] = round((float) $evaluation['totals']['selling_price'] * $quantity, 4);
        }

        return $this->build($model, $evaluation, $instance, $withBreakdown, $locale, $customer ?? $instance->customer_reference);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function build(CpqModel $model, array $evaluation, ?CpqConfiguredInstance $instance, bool $withBreakdown, ?string $locale, ?string $customer): array
    {
        $texts = $model->proposal_texts ?? [];
        $totals = $evaluation['totals'];

        $dossier = [
            'document' => [
                'reference' => $instance->part_number ?? $evaluation['part_number_preview'] ?? null,
                'generated_at' => now()->toIso8601String(),
                'valid_days' => (int) ($texts['validity_days'] ?? 30),
                'locale' => $locale ?? app()->getLocale(),
                'currency' => $model->currency,
                'customer' => $customer,
                'confirmed' => $instance !== null,
            ],
            'product' => [
                'model_code' => $model->code,
                'model_name' => Localized::pick($model->name, $locale),
                'description' => Localized::pick($model->description, $locale) ?: null,
                'part_number' => $instance?->part_number,
                'version' => $instance->model_version ?? $model->version,
                'hash' => $evaluation['configuration_hash'] ?? $instance?->configuration_hash,
            ],
            'texts' => [
                'title' => Localized::pick($texts['title'] ?? null, $locale) ?: null,
                'intro' => Localized::pick($texts['intro'] ?? null, $locale) ?: null,
                'warranty' => Localized::pick($texts['warranty'] ?? null, $locale) ?: null,
                'terms' => Localized::pick($texts['terms'] ?? null, $locale) ?: null,
                'delivery' => Localized::pick($texts['delivery'] ?? null, $locale) ?: null,
                'installation' => Localized::pick($texts['installation'] ?? null, $locale) ?: null,
            ],
            'specification' => $this->specification($evaluation),
            'auto_included' => array_map(static fn (array $entry): array => array_intersect_key($entry, array_flip(['characteristic_label', 'value_label', 'message'])), $evaluation['auto_included'] ?? []),
            'visual' => ['layers' => array_map(static fn (array $layer): array => array_intersect_key($layer, array_flip(['id', 'label', 'kind', 'color', 'z', 'auto_included'])), $evaluation['visual']['layers'] ?? [])],
            'dimensional_notes' => $this->notes($texts['dimensional_notes'] ?? [], $locale),
            'bom' => array_map(static fn (array $line): array => ['item_code' => $line['item_code'], 'name' => $line['name'], 'quantity' => $line['quantity'], 'uom' => $line['uom']], $evaluation['bom'] ?? []),
            'commercial' => [
                'quantity' => $totals['quantity'],
                'list_price' => $totals['list_price'],
                'discount_percent' => $totals['discount_percent'],
                'unit_price' => $totals['selling_price'],
                'total_price' => $totals['total_selling_price'],
                'lead_time_days' => $totals['lead_time_days'],
            ],
            'breakdown' => null,
        ];

        if ($withBreakdown) {
            $dossier['breakdown'] = [
                'industrial_cost' => $totals['industrial_cost'],
                'bom_cost' => $totals['bom_cost'],
                'routing_cost' => $totals['routing_cost'],
                'margin_amount' => $totals['margin_amount'],
                'margin_percent' => $totals['margin_percent'],
                'bom' => array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['item_code', 'name', 'quantity', 'unit_cost', 'line_cost'])), $evaluation['bom'] ?? []),
                'routing' => array_map(static fn (array $step): array => array_intersect_key($step, array_flip(['operation', 'work_center', 'hours', 'cost'])), $evaluation['routing'] ?? []),
            ];
        }

        return $dossier;
    }

    /**
     * Specification rows grouped by guided step: label, displayed value and unit of every visible characteristic.
     *
     * @param  array<string, mixed>  $evaluation
     * @return list<array{step: string|null, rows: list<array<string, mixed>>}>
     */
    private function specification(array $evaluation): array
    {
        $characteristics = [];

        foreach ($evaluation['characteristics'] ?? [] as $characteristic) {
            $characteristics[$characteristic['code']] = $characteristic;
        }

        $groups = [];

        foreach ($evaluation['steps'] ?? [] as $step) {
            $rows = [];

            foreach ($step['characteristics'] as $code) {
                $characteristic = $characteristics[$code] ?? null;

                if ($characteristic === null || ! $characteristic['visible'] || $characteristic['value'] === null) {
                    continue;
                }

                $rows[] = [
                    'label' => $characteristic['label'],
                    'value' => $this->display($characteristic),
                    'unit' => $characteristic['unit'],
                    'auto_included' => $characteristic['forced'],
                ];
            }

            if ($rows !== []) {
                $groups[] = ['step' => $step['title'], 'rows' => $rows];
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $characteristic
     */
    private function display(array $characteristic): string
    {
        if ($characteristic['type'] === CpqCharacteristic::TypeList) {
            foreach ($characteristic['options'] as $option) {
                if ($option['code'] === $characteristic['value']) {
                    return (string) $option['label'];
                }
            }
        }

        if ($characteristic['type'] === CpqCharacteristic::TypeBoolean) {
            return $characteristic['value'] === true ? '✓' : '—';
        }

        return ConfigurationHasher::number((float) $characteristic['value']);
    }

    /**
     * @param  mixed  $notes  list of {it, en, de, fr} maps
     * @return list<string>
     */
    private function notes(mixed $notes, ?string $locale): array
    {
        return array_values(array_filter(array_map(static fn (mixed $note): string => Localized::pick($note, $locale), is_array($notes) ? $notes : [])));
    }
}
