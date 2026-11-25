<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\VanSalesAndFieldForce\Domain\Enums\CollectionMethod;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitMode;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitOutcomeStatus;

final class VanVisitOutcomeStoreRequest extends FormRequest
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
            'field_route_plan_id' => ['required', 'uuid'],
            'van_load_manifest_id' => ['nullable', 'uuid'],
            'contact_id' => ['required', 'uuid'],
            'stop_sequence' => ['nullable', 'integer', 'min:1'],
            'visit_mode' => ['nullable', Rule::in(VisitMode::values())],
            'outcome_status' => ['required', Rule::in(VisitOutcomeStatus::values())],
            'no_sale_reason_code' => ['nullable', 'string', 'max:40'],
            'rescheduled_to' => ['nullable', 'date'],
            'document_type' => ['nullable', Rule::in(VanFiscalDocumentType::values())],
            'offline_document_number' => ['nullable', 'string', 'max:64'],
            'credit_override' => ['nullable', 'boolean'],
            'credit_blocked_initially' => ['nullable', 'boolean'],
            'unblocked_by_collection' => ['nullable', 'boolean'],
            'unblocking_payment_id' => ['nullable', 'uuid'],
            'collected_overdue_amount' => ['nullable', 'numeric', 'min:0'],
            'sale_lines' => ['nullable', 'array'],
            'sale_lines.*.product_id' => ['required_with:sale_lines', 'uuid'],
            'sale_lines.*.quantity' => ['required_with:sale_lines', 'numeric', 'gt:0'],
            'sale_lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'sale_lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sale_lines.*.lot_number' => ['nullable', 'string', 'max:80'],
            'sale_lines.*.expiry_date' => ['nullable', 'date'],
            'sale_lines.*.gs1_barcode' => ['nullable', 'string', 'max:255'],
            'sale_lines.*.is_variable_weight' => ['nullable', 'boolean'],
            'sale_lines.*.is_freebie' => ['nullable', 'boolean'],
            'return_lines' => ['nullable', 'array'],
            'empties_movements' => ['nullable', 'array'],
            'collections' => ['nullable', 'array'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(CollectionMethod::values())],
            'currency' => ['nullable', 'string', 'size:3'],
            'printer_format' => ['nullable', Rule::in(ThermalPrinterProfile::values())],
            'client_idempotency_key' => ['nullable', 'string', 'max:100'],
            'offline_created_at' => ['nullable', 'date'],
            'visited_at' => ['nullable', 'date'],
            'gps_latitude' => ['nullable', 'numeric'],
            'gps_longitude' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
