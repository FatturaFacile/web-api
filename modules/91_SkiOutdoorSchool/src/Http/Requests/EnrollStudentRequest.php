<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Enrol a student in a group course.
 */
final class EnrollStudentRequest extends FormRequest
{
    use SkiSchoolRules;

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
            'student_name' => ['required', 'string', 'max:160'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
            'guardian_name' => ['nullable', 'string', 'max:160'],
            'guardian_email' => ['nullable', 'email', 'max:160'],
            'emergency_contact_name' => ['required', 'string', 'max:160'],
            'emergency_contact_phone' => ['required', 'string', 'max:40'],
            'price_net' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
        ];
    }
}
