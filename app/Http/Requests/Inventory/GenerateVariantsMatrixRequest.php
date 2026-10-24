<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Actions\Inventory\GenerateProductVariantsAction;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Bulk generation of the variants of a parent product from an attribute matrix.
 */
class GenerateVariantsMatrixRequest extends ProductVariantRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attributes' => ['required', 'array', 'min:1', 'max:'.self::MAX_ATTRIBUTES],
            'attributes.*' => ['array', 'min:1'],
            'price_modifier' => ['nullable', 'array'],
            'price_modifier.type' => ['required_with:price_modifier', Rule::in(['fixed', 'percent'])],
            'price_modifier.value' => ['required_with:price_modifier', 'numeric', 'between:-1000000,1000000'],
            'auto_generate_sku' => ['sometimes', 'boolean'],
            'generate_barcode' => ['sometimes', 'boolean'],
            'barcode_type' => ['sometimes', Rule::in(['ean13', 'code128'])],
            'discontinue_on_zero_stock' => ['sometimes', 'boolean'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * The attribute matrix with trimmed string values.
     *
     * @return array<string, array<int, string>>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach ((array) $this->input('attributes', []) as $name => $values) {
            $matrix[trim((string) $name)] = array_map(fn (mixed $value): string => trim((string) $value), array_values((array) $values));
        }

        return $matrix;
    }

    /**
     * Options of {@see GenerateProductVariantsAction::execute()}.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'price_modifier' => $this->input('price_modifier'),
            'auto_generate_sku' => $this->boolean('auto_generate_sku', true),
            'generate_barcode' => $this->boolean('generate_barcode'),
            'barcode_type' => $this->input('barcode_type', 'ean13'),
            'discontinue_on_zero_stock' => $this->boolean('discontinue_on_zero_stock'),
            'min_stock' => $this->input('min_stock'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $count = 1;

                foreach ((array) $this->input('attributes') as $name => $values) {
                    if (trim((string) $name) === '' || mb_strlen(trim((string) $name)) > 50 || (string) $name === Product::DISCONTINUE_FLAG) {
                        $validator->errors()->add('attributes', __('inventory.attribute_name_invalid'));

                        return;
                    }

                    $seen = [];

                    foreach ((array) $values as $value) {
                        if (! is_scalar($value) || is_bool($value) || trim((string) $value) === '' || mb_strlen(trim((string) $value)) > 100) {
                            $validator->errors()->add("attributes.{$name}", __('inventory.attribute_value_invalid', ['attribute' => $name]));

                            return;
                        }

                        $key = mb_strtolower(trim((string) $value));

                        if (isset($seen[$key])) {
                            $validator->errors()->add("attributes.{$name}", __('inventory.attribute_values_duplicated', ['attribute' => $name]));

                            return;
                        }

                        $seen[$key] = true;
                    }

                    $count *= count($seen);
                }

                if ($count > GenerateProductVariantsAction::MAX_COMBINATIONS) {
                    $validator->errors()->add('attributes', __('inventory.too_many_combinations', ['count' => $count, 'max' => GenerateProductVariantsAction::MAX_COMBINATIONS]));
                }

                $modifier = $this->input('price_modifier');

                if (is_array($modifier) && ($modifier['type'] ?? null) === 'percent' && (float) ($modifier['value'] ?? 0) < -100) {
                    $validator->errors()->add('price_modifier.value', __('inventory.price_modifier_invalid'));
                }
            },
        ];
    }
}
