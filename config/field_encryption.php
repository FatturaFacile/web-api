<?php

declare(strict_types=1);
use App\Models\Contact;

return [
    /*
    |--------------------------------------------------------------------------
    | Field-level envelope encryption (ISO 27001 A.8.24, GDPR Art. 32, revFADP Art. 8)
    |--------------------------------------------------------------------------
    | The KEK (key-encryption key, 32 random bytes, base64) is the root secret and belongs in a KMS or the
    | environment. Tenant data-encryption keys (DEKs) and blind-index keys are derived from it per tenant and version
    | with HKDF-SHA256, so a rotation is a version bump: new writes use `current_version`, old ciphertexts stay
    | readable until they are re-encrypted by the rotation command.
    */
    'kek' => env('FIELD_ENCRYPTION_KEK'),

    'current_version' => (int) env('FIELD_ENCRYPTION_DEK_VERSION', 1),

    /*
    | Models using App\Models\Concerns\HasEncryptedFields, by the alias the unmask endpoint accepts. The rotation
    | command and the posture coverage KPI walk this list.
    */
    'models' => [
        'contact' => Contact::class,
    ],
];
