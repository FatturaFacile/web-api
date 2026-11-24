<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the technician a work order is dispatched to: it must belong to the active tenant.
 */
final class DispatchWorkOrderRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access:service middleware.
     */
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
            'technician_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
        ];
    }
}
