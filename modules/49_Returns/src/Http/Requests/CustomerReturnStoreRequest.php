<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\CustomerReturn;

final class CustomerReturnStoreRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'delivery_note_id' => ['nullable', 'uuid'],
            'sales_order_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
            'reason_code' => ['required', 'string', 'max:40'],
            'channel' => ['nullable', Rule::in(CustomerReturn::Channels)],
            'requested_on' => ['nullable', 'date_format:Y-m-d'],
            'override_window' => ['nullable', 'boolean'],
            'restocking_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:80'],
            'return_tracking_number' => ['nullable', 'string', 'max:100'],
            'photo_urls' => ['nullable', 'array'],
            'photo_urls.*' => ['string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_item_id' => ['nullable', 'uuid'],
            'lines.*.delivery_note_item_id' => ['nullable', 'uuid'],
            'lines.*.sales_order_item_id' => ['nullable', 'uuid'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.reason_code' => ['nullable', 'string', 'max:40'],
            'lines.*.photo_urls' => ['nullable', 'array'],
            'lines.*.photo_urls.*' => ['string', 'max:500'],
        ];
    }
}
