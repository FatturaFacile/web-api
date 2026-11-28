<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;

/**
 * Settlement (and its preview) of a milling cycle.
 */
final class SettleCycleRequest extends FormRequest
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
            'tariff_mode' => ['required', Rule::enum(TariffMode::class)],
            'tariff_value' => ['required', 'numeric', 'min:0'],
            'service_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settlement_mode' => ['required', Rule::enum(SettlementMode::class)],
            'molenda_percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'fixed_retained_kg' => ['nullable', 'numeric', 'gt:0'],
            'oil_price_per_kg' => ['required_unless:settlement_mode,'.SettlementMode::CashOrBank->value, 'nullable', 'numeric', 'min:0'],
            'purchase_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tank_id' => ['nullable', 'uuid', Rule::exists('mill_tanks', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
