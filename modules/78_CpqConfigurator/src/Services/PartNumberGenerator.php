<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;

/**
 * Smart part numbers: `<model prefix>-<segment>-<segment>…-<sequence>`. A segment comes from a characteristic that has a
 * `part_number` rule: the `part_number_code` of the chosen option, the true/false code of a boolean, a prefix plus the
 * value of a range (`F120`). The sequence is a per-model counter, so two different configurations never share a number.
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 */
final class PartNumberGenerator
{
    private const int MaxLength = 80;

    /**
     * The part number without its sequence, as the sales representative sees it before confirming.
     *
     * @param  Definition  $definition
     * @param  array<string, mixed>  $values
     * @param  array<string, bool>  $visible
     */
    public function stem(array $definition, array $values, array $visible): string
    {
        $segments = [];

        foreach ($definition['characteristics'] as $code => $characteristic) {
            $rule = $characteristic['part_number'] ?? null;

            if (! is_array($rule) || ! isset($rule['position']) || ! ($visible[$code] ?? true)) {
                continue;
            }

            $segment = $this->segment($characteristic, $rule, $values[$code] ?? null);

            if ($segment !== '') {
                $segments[] = [(int) $rule['position'], $code, $segment];
            }
        }

        usort($segments, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('-', [$definition['part_number_prefix'], ...array_column($segments, 2)]);
    }

    /**
     * The next free part number of the model. Call it inside the transaction that holds the lock on the model row.
     *
     * @param  Definition  $definition
     * @param  array<string, mixed>  $values
     * @param  array<string, bool>  $visible
     */
    public function next(CpqModel $model, array $definition, array $values, array $visible): string
    {
        $stem = $this->stem($definition, $values, $visible);

        do {
            $model->part_number_sequence++;
            $candidate = $this->fit($stem, str_pad((string) $model->part_number_sequence, 4, '0', STR_PAD_LEFT));
        } while (CpqConfiguredInstance::withTrashed()->where('part_number', $candidate)->exists());

        $model->save();

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $characteristic
     * @param  array<string, mixed>  $rule
     */
    private function segment(array $characteristic, array $rule, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = match ($characteristic['type']) {
            CpqCharacteristic::TypeList => $this->optionCode($characteristic, (string) $value),
            CpqCharacteristic::TypeBoolean => (string) ($value ? ($rule['true_code'] ?? '') : ($rule['false_code'] ?? '')),
            default => (string) ($rule['prefix'] ?? '').ConfigurationHasher::number((float) $value),
        };

        return strtoupper((string) preg_replace('/[^A-Za-z0-9.]/', '', $text));
    }

    /**
     * @param  array<string, mixed>  $characteristic
     */
    private function optionCode(array $characteristic, string $value): string
    {
        foreach ($characteristic['options'] as $option) {
            if ($option['code'] === $value) {
                return (string) ($option['part_number_code'] ?? '');
            }
        }

        return '';
    }

    private function fit(string $stem, string $sequence): string
    {
        $room = self::MaxLength - strlen($sequence) - 1;

        return substr($stem, 0, $room).'-'.$sequence;
    }
}
