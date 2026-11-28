<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rectification of a tank stock (natural loss, count difference).
 */
final class AdjustTankRequest extends FormRequest
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
            'tank_id' => ['required', 'uuid', Rule::exists('mill_tanks', 'id')->where('tenant_id', $tenantId)],
            'quantity_kg' => ['required', 'numeric', 'gt:0'],
            'operation_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
