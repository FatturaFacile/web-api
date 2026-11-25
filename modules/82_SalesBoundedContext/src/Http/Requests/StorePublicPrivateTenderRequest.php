<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\TenderProcurementFramework;

final class StorePublicPrivateTenderRequest extends FormRequest
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
        return [
            'dossier_id' => ['nullable', 'uuid'],
            'tender_code' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:180'],
            'procurement_framework' => ['required', Rule::in(TenderProcurementFramework::values())],
            'contracting_authority' => ['required', 'string', 'max:180'],
            'cig_code' => ['nullable', 'string', 'max:24'],
            'cup_code' => ['nullable', 'string', 'max:24'],
            'soa_category' => ['nullable', 'string', 'max:32'],
            'edgue_status' => ['nullable', 'string', 'max:32'],
            'fvoe_passoe_ref' => ['nullable', 'string', 'max:64'],
            'qualification_vault' => ['nullable', 'array'],
            'admin_envelope_status' => ['nullable', 'string', 'max:32'],
            'technical_envelope_score' => ['nullable', 'numeric', 'min:0', 'max:70'],
            'economic_envelope_score' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'bid_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'pipeline_stage' => ['nullable', 'string', 'in:scouted,submitted,awarded,contract_converted,lost'],
        ];
    }
}
