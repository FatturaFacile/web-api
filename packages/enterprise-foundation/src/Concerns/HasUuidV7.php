<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Illuminate\Support\Str;

trait HasUuidV7
{
    public function initializeHasUuidV7(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;

        if (empty($this->attributes[$this->getKeyName()])) {
            $this->attributes[$this->getKeyName()] = (string) Str::uuid7();
        }
    }

    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getKeyName()})) {
                // Generates time-ordered RFC 9562 UUIDv7
                $model->{$model->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
