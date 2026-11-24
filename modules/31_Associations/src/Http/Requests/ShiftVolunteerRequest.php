<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

/**
 * A volunteer for a shift: its availability, an assignment or a substitution.
 */
final class ShiftVolunteerRequest extends FormRequest
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
            'volunteer_id' => ['required', 'uuid', TenantRules::exists('assoc_volunteers')],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }
}
