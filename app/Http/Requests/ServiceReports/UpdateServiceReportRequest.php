<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceReportRequest extends FormRequest
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
            'contact_id' => ['sometimes', 'required', 'uuid', 'exists:contacts,id'],
            'technician_id' => ['sometimes', 'required', 'uuid', 'exists:users,id'],
            'report_date' => ['sometimes', 'required', 'date'],
            'status_id' => ['nullable', 'uuid', 'exists:lookup_items,id'],
            'ticket_id' => ['nullable', 'uuid', 'exists:tickets,id'],
            'project_id' => ['nullable', 'uuid'],
            'time_start' => ['nullable', 'date_format:H:i'],
            'time_end' => ['nullable', 'date_format:H:i'],
            'travel_distance_km' => ['nullable', 'numeric', 'min:0'],
            'site_address' => ['nullable', 'string', 'max:255'],
            'customer_notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'is_portal_visible' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.line_type_id' => ['required_with:lines', 'uuid', 'exists:lookup_items,id'],
            'lines.*.item_id' => ['nullable', 'uuid', 'exists:products,id'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.0001'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.vat_code_id' => ['nullable', 'uuid', 'exists:vat_codes,id'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.is_billable' => ['nullable', 'boolean'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
