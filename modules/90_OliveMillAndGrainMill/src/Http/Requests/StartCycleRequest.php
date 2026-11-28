<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start of a milling cycle for a weighed delivery.
 */
final class StartCycleRequest extends FormRequest
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
            'delivery_id' => ['required', 'uuid', Rule::exists('mill_deliveries', 'id')->where('tenant_id', $tenantId)],
            'line_code' => ['nullable', 'string', 'max:30'],
            'started_at' => ['nullable', 'date'],
            'malaxation_temperature_c' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'water_added_l' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
