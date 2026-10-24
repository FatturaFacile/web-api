<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared handling of the `attributes` map of a variant: option values (size, colour, ...) plus the optional
 * `discontinue_on_zero_stock` lifecycle flag, which may also be sent as a top-level field.
 */
abstract class ProductVariantRequest extends FormRequest
{
    public const MAX_ATTRIBUTES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The option attributes (size, colour, ...) sent, without the lifecycle flag.
     *
     * @return array<string, string>
     */
    public function optionAttributes(): array
    {
        $options = [];

        foreach ((array) $this->input('attributes', []) as $name => $value) {
            if ((string) $name !== Product::DISCONTINUE_FLAG) {
                $options[trim((string) $name)] = trim((string) $value);
            }
        }

        return $options;
    }

    /**
     * The `discontinue_on_zero_stock` flag, or null when the request does not mention it.
     */
    public function discontinueFlag(): ?bool
    {
        $value = $this->has(Product::DISCONTINUE_FLAG)
            ? $this->input(Product::DISCONTINUE_FLAG)
            : ((array) $this->input('attributes', []))[Product::DISCONTINUE_FLAG] ?? null;

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Validate the `attributes` map: names, values, the flag and the maximum number of combinable attributes.
     */
    protected function validateOptionAttributes(Validator $validator): void
    {
        $attributes = $this->input('attributes');

        if (! is_array($attributes) || $validator->errors()->has('attributes')) {
            return;
        }

        $options = 0;

        foreach ($attributes as $name => $value) {
            if ((string) $name === Product::DISCONTINUE_FLAG) {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                    $validator->errors()->add('attributes.'.Product::DISCONTINUE_FLAG, __('validation.boolean', ['attribute' => Product::DISCONTINUE_FLAG]));
                }

                continue;
            }

            $options++;

            if (trim((string) $name) === '' || mb_strlen(trim((string) $name)) > 50) {
                $validator->errors()->add('attributes', __('inventory.attribute_name_invalid'));
            } elseif (! is_scalar($value) || is_bool($value) || trim((string) $value) === '' || mb_strlen(trim((string) $value)) > 100) {
                $validator->errors()->add("attributes.{$name}", __('inventory.attribute_value_invalid', ['attribute' => $name]));
            }
        }

        if ($options === 0) {
            $validator->errors()->add('attributes', __('inventory.attributes_required'));
        } elseif ($options > self::MAX_ATTRIBUTES) {
            $validator->errors()->add('attributes', __('inventory.attributes_max', ['max' => self::MAX_ATTRIBUTES]));
        }
    }
}
