<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

/**
 * The stored attributes of the wrapped model with every `decimal:N` attribute read through its cast. The raw value of a
 * decimal column is a string on PostgreSQL but an int or a float on SQLite, so the payload would change with the
 * engine; the cast gives the same fixed-scale string everywhere. Dates and the other attributes stay as stored.
 */
trait SerializesDecimalAttributes
{
    /**
     * @return array<string, mixed>
     */
    protected function attributesWithDecimals(): array
    {
        $attributes = $this->resource->getAttributes();

        foreach ($this->resource->getCasts() as $key => $cast) {
            if (is_string($cast) && str_starts_with($cast, 'decimal:') && array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = $this->resource->getAttribute($key);
            }
        }

        return $attributes;
    }
}
