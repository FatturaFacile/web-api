<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VanEodSettlementPreviewRequest extends FormRequest
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
            'van_warehouse_id' => ['required', 'uuid'],
            'van_load_manifest_id' => ['nullable', 'uuid'],
            'settlement_date' => ['nullable', 'date'],
        ];
    }
}
