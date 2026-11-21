<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The segregated vault of the clinical notes of a visit, encrypted at rest. Only the physician role reads it, through
 * MedicalClinicalVault; no resource, query or audit payload carries its content.
 *
 * @property string $medical_visit_id
 * @property string $encrypted_notes
 */
final class MedicalClinicalVaultEntry extends BaseEnterpriseModel
{
    protected $table = 'hse_medical_clinical_vault';

    /** @var list<string> */
    protected $hidden = ['encrypted_notes'];

    protected function casts(): array
    {
        return ['encrypted_notes' => 'encrypted'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['encrypted_notes']);

        return $attributes;
    }
}
