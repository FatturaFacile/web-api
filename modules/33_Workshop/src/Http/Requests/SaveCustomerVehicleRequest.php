<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer vehicle (the "vehicles" register used by workshop folders, recurring services and the tire hotel).
 */
final class SaveCustomerVehicleRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['license_plate', 'vin_chassis'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(preg_replace('/\s+/', ' ', trim($this->input($field))) ?? '')]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'client_id' => [$presence, 'uuid', $this->tenantOwns('contacts')],
            'license_plate' => [$presence, 'string', 'max:20', Rule::unique('vehicles', 'license_plate')->where('tenant_id', $tenantId)->ignore($this->route('id'))],
            'vin_chassis' => ['sometimes', 'nullable', 'string', 'max:30'],
            'make' => [$presence, 'string', 'max:50'],
            'model' => [$presence, 'string', 'max:50'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'registration_date' => ['sometimes', 'nullable', 'date'],
            'mileage_km' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
