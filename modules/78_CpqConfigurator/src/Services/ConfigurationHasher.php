<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Models\CpqCharacteristic;

/**
 * Canonical form and SHA-256 of a configuration. Two configurations that mean the same product give the same hash,
 * whatever the order of the answers, the way numbers are written or the answers of characteristics that are hidden:
 * the canonical payload holds the model, its version and the final value of every visible characteristic, sorted by code,
 * numbers as trimmed decimal strings and booleans as "true"/"false".
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 */
final class ConfigurationHasher
{
    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $values  final values of the solver
     * @param  array<string, bool>  $visible
     * @return array{model: string, version: int, values: array<string, string>}
     */
    public function canonical(array $definition, array $values, array $visible): array
    {
        $canonical = [];

        foreach ($definition['characteristics'] as $code => $characteristic) {
            if (! ($visible[$code] ?? true) || ($values[$code] ?? null) === null) {
                continue;
            }

            $canonical[$code] = $this->normalise($characteristic['type'], $values[$code]);
        }

        ksort($canonical, SORT_STRING);

        return ['model' => $definition['id'], 'version' => $definition['version'], 'values' => $canonical];
    }

    /**
     * @param  array{model: string, version: int, values: array<string, string>}  $canonical
     */
    public function hash(array $canonical): string
    {
        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function normalise(string $type, mixed $value): string
    {
        return match ($type) {
            CpqCharacteristic::TypeRange => self::number((float) $value),
            CpqCharacteristic::TypeBoolean => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
