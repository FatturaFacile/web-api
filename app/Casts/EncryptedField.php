<?php

declare(strict_types=1);

namespace App\Casts;

use App\Services\Security\FieldEncryptionContext;
use App\Services\Security\FieldEncryptionService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Transparent AES-256-GCM envelope encryption of an attribute, bound to tenant, table, row and field. Legacy plain
 * values (before the rotation command encrypted them) are still read as they are.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedField implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === null ? null : '';
        }

        $value = (string) $value;

        if (! FieldEncryptionService::isCiphertext($value)) {
            return $value;
        }

        return app(FieldEncryptionService::class)->decrypt($value, FieldEncryptionContext::forModel($model, $key, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Stored as given; the creating/updating hook of HasEncryptedFields encrypts it once tenant and key are known.
        return $value === null ? null : (string) $value;
    }
}
