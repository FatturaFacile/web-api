<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class BarcodeScanRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'raw_payload' => ['required', 'string', 'max:500'],
            'symbology' => ['required', 'string', 'in:auto,GS1-128,EAN-13,CODE-128,QR,DataMatrix'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
        ];
    }
}
