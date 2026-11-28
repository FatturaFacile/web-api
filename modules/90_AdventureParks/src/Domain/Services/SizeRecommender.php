<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Services;

use Modules\AdventureParks\Domain\Enums\PpeSize;

/**
 * Recommends the harness size from the height and the weight of the visitor, using the size table of the configuration: the smallest
 * size whose maxima cover both measures, the largest size otherwise.
 */
final readonly class SizeRecommender
{
    /**
     * @param  array<string, array{min_height_cm: int, max_height_cm: int, min_weight_kg: int, max_weight_kg: int}>  $table
     */
    public function __construct(private array $table) {}

    public static function fromConfig(): self
    {
        /** @var array<string, array{min_height_cm: int, max_height_cm: int, min_weight_kg: int, max_weight_kg: int}> $table */
        $table = (array) config('adventure_parks.sizes');

        return new self($table);
    }

    public function recommend(float $heightCm, float $weightKg): PpeSize
    {
        $last = PpeSize::Xl;

        foreach ($this->table as $size => $range) {
            $last = PpeSize::from($size);

            if ($heightCm <= $range['max_height_cm'] && $weightKg <= $range['max_weight_kg']) {
                return $last;
            }
        }

        return PpeSize::Xl;
    }

    /**
     * @return list<array{size: string, min_height_cm: int, max_height_cm: int, min_weight_kg: int, max_weight_kg: int}>
     */
    public function table(): array
    {
        $rows = [];

        foreach ($this->table as $size => $range) {
            $rows[] = ['size' => $size, ...$range];
        }

        return $rows;
    }
}
