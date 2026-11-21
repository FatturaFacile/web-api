<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\VisitType;

final class RecordMedicalFitnessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'doctor_name' => ['required', 'string', 'max:160'],
            'doctor_registration_number' => ['nullable', 'string', 'max:64'],
            'visit_type' => ['required', Rule::enum(VisitType::class)],
            'visit_date' => ['required', 'date'],
            'next_visit_due_date' => ['nullable', 'date', 'after_or_equal:visit_date'],
            'fitness_outcome' => ['required', Rule::enum(FitnessOutcome::class)],
            'restrictions_summary' => ['nullable', 'string', 'max:2000'],
            'clinical_notes' => ['nullable', 'string', 'max:20000'],
            'certificate_document_id' => ['nullable', 'uuid'],
        ];
    }
}
