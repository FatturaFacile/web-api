<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transfer or blend between two tanks.
 */
final class TransferTanksRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'from_tank_id' => ['required', 'uuid', Rule::exists('mill_tanks', 'id')->where('tenant_id', $tenantId)],
            'to_tank_id' => ['required', 'uuid', 'different:from_tank_id', Rule::exists('mill_tanks', 'id')->where('tenant_id', $tenantId)],
            'quantity_kg' => ['required', 'numeric', 'gt:0'],
            'operation_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
