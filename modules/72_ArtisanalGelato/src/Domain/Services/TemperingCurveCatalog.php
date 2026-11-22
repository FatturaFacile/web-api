<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Services;

use InvalidArgumentException;

/**
 * Reference tempering curves (melt, pre-crystallisation, working temperature, in degrees Celsius) of dark (beta V), milk and white
 * chocolate, and the check of a measured curve against them.
 */
final class TemperingCurveCatalog
{
    /** @var array<string, array{melt: array{0: float, 1: float}, cool: array{0: float, 1: float}, work: array{0: float, 1: float}}> */
    private const array CURVES = [
        'dark' => ['melt' => [45.0, 50.0], 'cool' => [27.0, 28.0], 'work' => [31.0, 32.0]],
        'milk' => ['melt' => [40.0, 45.0], 'cool' => [26.0, 27.0], 'work' => [29.0, 30.5]],
        'white' => ['melt' => [40.0, 45.0], 'cool' => [25.0, 26.0], 'work' => [28.0, 29.0]],
    ];

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::CURVES);
    }

    /**
     * @return array{melt: array{0: float, 1: float}, cool: array{0: float, 1: float}, work: array{0: float, 1: float}}
     */
    public function curve(string $type): array
    {
        return self::CURVES[$type] ?? throw new InvalidArgumentException("Unknown chocolate type {$type}.");
    }

    /**
     * @param  array{melt: float|int, cool: float|int, work: float|int}  $measured
     * @return array{valid: bool, stages: array<string, array{value: float, min: float, max: float, ok: bool}>}
     */
    public function validate(string $type, array $measured): array
    {
        $stages = [];
        $valid = true;

        foreach ($this->curve($type) as $stage => [$min, $max]) {
            $value = (float) $measured[$stage];
            $ok = $value >= $min && $value <= $max;
            $valid = $valid && $ok;
            $stages[$stage] = ['value' => $value, 'min' => $min, 'max' => $max, 'ok' => $ok];
        }

        return ['valid' => $valid, 'stages' => $stages];
    }
}
