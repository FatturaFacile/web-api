<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Validation\ValidationException;
use Modules\Associations\Models\CustomField;

/**
 * Checks the custom values of a person against the fields the company configured.
 */
final class CustomFieldValidator
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $values): array
    {
        $fields = CustomField::query()->where('is_active', true)->get()->keyBy('code');
        $errors = [];

        foreach (array_diff(array_keys($values), $fields->keys()->all()) as $unknown) {
            $errors["custom_fields.{$unknown}"] = [__('associations.custom_field_unknown')];
        }

        foreach ($fields as $code => $field) {
            $value = $values[$code] ?? null;

            if ($value === null || $value === '') {
                if ($field->is_required) {
                    $errors["custom_fields.{$code}"] = [__('associations.custom_field_required')];
                }

                continue;
            }

            if (! $this->matchesType($field, $value)) {
                $errors["custom_fields.{$code}"] = [__('associations.custom_field_invalid')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_intersect_key($values, $fields->all());
    }

    private function matchesType(CustomField $field, mixed $value): bool
    {
        return match ($field->type) {
            'number' => is_numeric($value),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            'date' => is_string($value) && strtotime($value) !== false,
            'select' => in_array($value, (array) $field->options, true),
            default => is_scalar($value),
        };
    }
}
