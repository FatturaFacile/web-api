<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;

/**
 * Booking (and optional weighing) of a crop delivery.
 */
final class SaveDeliveryRequest extends FormRequest
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
            'delivered_at' => ['required', 'date'],
            'processing_mode' => ['required', Rule::enum(ProcessingMode::class)],
            'commodity' => ['required', Rule::enum(Commodity::class)],
            'grain_type' => ['nullable', 'string', 'max:40'],
            'farmer_contact_id' => ['required_if:processing_mode,'.ProcessingMode::ContoTerzi->value, 'nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'cultivar' => ['nullable', 'string', 'max:80'],
            'origin_municipality' => ['nullable', 'string', 'max:120'],
            'cadastral_reference' => ['nullable', 'string', 'max:80'],
            'sian_origin_code' => ['nullable', 'string', 'max:40'],
            'certification' => ['required', Rule::enum(Certification::class)],
            'scheduled_at' => ['nullable', 'date'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'hopper_line' => ['nullable', 'string', 'max:30'],
            'sms_recipient_phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()\-]{6,40}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'weighing' => ['nullable', 'array'],
            'weighing.gross_weight_kg' => ['required_with:weighing', 'numeric', 'gt:0'],
            'weighing.tare_kg' => ['nullable', 'numeric', 'min:0'],
            'weighing.crate_count' => ['nullable', 'integer', 'min:0'],
            'weighing.crate_unit_tare_kg' => ['nullable', 'numeric', 'min:0'],
            'weighing.weighbridge_ticket' => ['nullable', 'string', 'max:60'],
            'weighing.scale_payload' => ['nullable', 'array'],
            'weighing.scale_read_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('weighing'))) {
            $weighing = $this->input('weighing');
            $weighing['tare_kg'] ??= 0;
            $weighing['crate_count'] ??= 0;
            $weighing['crate_unit_tare_kg'] ??= 0;
            $this->merge(['weighing' => $weighing]);
        }
    }
}
