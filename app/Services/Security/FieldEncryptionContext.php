<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Identifies one encrypted attribute. Its parts form the AES-GCM additional authenticated data, so a ciphertext copied
 * to another tenant, entity or field fails authentication.
 */
final readonly class FieldEncryptionContext
{
    public function __construct(
        public string $tenantId,
        public string $entityType,
        public string $entityId,
        public string $fieldName,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function forModel(Model $model, string $field, array $attributes = []): self
    {
        return new self(self::tenantOf($model, $attributes), $model->getTable(), self::entityId($model), $field);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function tenantOf(Model $model, array $attributes = []): string
    {
        $tenant = $attributes['tenant_id'] ?? $model->getAttribute('tenant_id') ?? app(TenantContext::class)->getTenantId();

        return is_scalar($tenant) && (string) $tenant !== '' ? (string) $tenant : 'global';
    }

    /**
     * A not yet persisted UUID model gets its key now, so the AAD stays the same after the insert.
     */
    private static function entityId(Model $model): string
    {
        $key = $model->getKey();

        if (($key === null || $key === '') && $model->usesUniqueIds()) {
            $key = $model->newUniqueId();
            $model->setAttribute($model->getKeyName(), $key);
        }

        return is_scalar($key) ? (string) $key : '';
    }

    public function aad(): string
    {
        return implode('|', [$this->tenantId, $this->entityType, $this->entityId, $this->fieldName]);
    }
}
