<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OliveMillAndGrainMill\Domain\Enums\BottleFormat;

/**
 * Bottling of oil from a tank.
 */
final class PackageTankRequest extends FormRequest
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
            'bottle_format' => ['required', Rule::enum(BottleFormat::class)],
            'bottle_count' => ['required', 'integer', 'min:1', 'max:1000000'],
            'operation_date' => ['nullable', 'date'],
            'package_lot' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
