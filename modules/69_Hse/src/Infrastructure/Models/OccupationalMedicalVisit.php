<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\VisitType;

/**
 * Aggregate root (GDPR Art. 9 / nLPD hardened): the physician judgment and its operational prescriptions. The clinical
 * notes are stored in the segregated vault and the reference to it is hidden from every serialization and audit payload.
 *
 * @property string $employee_id
 * @property string $doctor_name
 * @property string|null $doctor_registration_number
 * @property VisitType $visit_type
 * @property Carbon $visit_date
 * @property Carbon|null $next_visit_due_date
 * @property FitnessOutcome $fitness_outcome
 * @property string|null $restrictions_summary
 * @property string|null $encrypted_clinical_notes_ref
 * @property string|null $certificate_document_id
 */
final class OccupationalMedicalVisit extends BaseEnterpriseModel
{
    protected $table = 'hse_medical_visits';

    /** @var list<string> */
    protected $hidden = ['encrypted_clinical_notes_ref'];

    protected function casts(): array
    {
        return [
            'visit_type' => VisitType::class,
            'fitness_outcome' => FitnessOutcome::class,
            'visit_date' => 'date',
            'next_visit_due_date' => 'date',
        ];
    }

    /**
     * The audit ledger never sees the vault reference.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['encrypted_clinical_notes_ref']);

        return $attributes;
    }
}
