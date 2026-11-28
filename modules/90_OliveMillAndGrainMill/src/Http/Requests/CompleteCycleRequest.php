<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;

/**
 * Completion of a milling cycle: output, lab values, by-products and destinations.
 */
final class CompleteCycleRequest extends FormRequest
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
            'ended_at' => ['nullable', 'date'],
            'output_kg' => ['required_without:output_liters', 'nullable', 'numeric', 'gt:0'],
            'output_liters' => ['required_without:output_kg', 'nullable', 'numeric', 'gt:0'],
            'malaxation_temperature_c' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'water_added_l' => ['nullable', 'numeric', 'min:0'],
            'acidity_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'peroxides' => ['nullable', 'numeric', 'min:0'],
            'polyphenols_mg_kg' => ['nullable', 'numeric', 'min:0'],
            'panel_notes' => ['nullable', 'string', 'max:2000'],
            'sansa_kg' => ['nullable', 'numeric', 'min:0'],
            'nocciolino_kg' => ['nullable', 'numeric', 'min:0'],
            'vegetation_water_l' => ['nullable', 'numeric', 'min:0'],
            'bran_kg' => ['nullable', 'numeric', 'min:0'],
            'pickup_kg' => ['nullable', 'numeric', 'min:0'],
            'molenda_kg' => ['nullable', 'numeric', 'min:0'],
            'mill_stock_kg' => ['nullable', 'numeric', 'min:0'],
            'target_tank_id' => ['nullable', 'uuid', Rule::exists('mill_tanks', 'id')->where('tenant_id', $tenantId)],
            'origin_designation' => ['nullable', Rule::enum(OriginDesignation::class)],
        ];
    }
}
