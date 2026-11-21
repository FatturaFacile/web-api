<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Security\FieldEncryptionContext;
use App\Services\Security\FieldEncryptionService;

/**
 * Declares the encrypted fields of a model and keeps their blind-index columns in step. The model declares
 * `protected array $encryptedFields = ['iban' => 'iban_bidx', 'qr_iban' => null]` (field => blind-index column or null)
 * and casts each field with `App\Casts\EncryptedField`.
 *
 * Encryption happens in the creating/updating events, after the tenant and the key are assigned, because both are part
 * of the AES-GCM additional authenticated data. List this trait after BelongsToTenant and HasUuids in the model.
 */
trait HasEncryptedFields
{
    public static function bootHasEncryptedFields(): void
    {
        $encrypt = static function ($model): void {
            $service = app(FieldEncryptionService::class);
            $raw = $model->getAttributes();
            $changed = false;

            foreach ($model->encryptedFieldMap() as $field => $indexColumn) {
                $value = $raw[$field] ?? null;

                if (! $model->isDirty($field) || ! is_string($value) || $value === '' || FieldEncryptionService::isCiphertext($value)) {
                    continue;
                }

                $tenantId = FieldEncryptionContext::tenantOf($model);
                $raw[$field] = $service->encrypt($value, FieldEncryptionContext::forModel($model, $field));

                if ($indexColumn !== null) {
                    $raw[$indexColumn] = $service->blindIndex($value, $tenantId, $field);
                }

                $changed = true;
            }

            foreach ($model->encryptedFieldMap() as $field => $indexColumn) {
                if ($indexColumn !== null && $model->isDirty($field) && ($raw[$field] ?? null) === null) {
                    $raw[$indexColumn] = null;
                    $changed = true;
                }
            }

            if ($changed) {
                $model->setRawAttributes($raw, false);
            }
        };

        static::creating($encrypt);
        static::updating($encrypt);
    }

    /**
     * @return array<string, string|null>
     */
    public function encryptedFieldMap(): array
    {
        return $this->encryptedFields;
    }
}
