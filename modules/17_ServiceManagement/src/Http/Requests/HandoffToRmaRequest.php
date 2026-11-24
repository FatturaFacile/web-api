<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One-click handoff of a ticket to an RMA return. The unit is named by its serial number or equipment passport, unless the
 * ticket already points to it.
 */
final class HandoffToRmaRequest extends FormRequest
{
    use TenantScopedRules;

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
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'equipment_passport_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_equipment_passports')],
            'goodwill' => ['sometimes', 'boolean'],
            'reported_symptom' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ];
    }
}
