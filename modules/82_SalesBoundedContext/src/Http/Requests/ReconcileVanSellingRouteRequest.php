<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReconcileVanSellingRouteRequest extends FormRequest
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
            'route_code' => ['nullable', 'string', 'max:40'],
            'driver_agent_name' => ['required', 'string', 'max:120'],
            'vehicle_plate' => ['required', 'string', 'max:32'],
            'traveling_warehouse_code' => ['nullable', 'string', 'max:40'],
            'document_series_sezionale' => ['nullable', 'string', 'max:24'],
            'loaded_stock_qty' => ['required', 'numeric', 'min:0'],
            'sold_stock_qty' => ['required', 'numeric', 'min:0'],
            'returned_unsold_qty' => ['required', 'numeric', 'min:0'],
            'expected_cash_amount' => ['required', 'numeric', 'min:0'],
            'collected_cash_amount' => ['required', 'numeric', 'min:0'],
            'electronic_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'offline_sync_batch_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
