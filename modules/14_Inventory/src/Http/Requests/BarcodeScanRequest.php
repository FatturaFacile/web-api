<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BarcodeScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'raw_payload' => ['required', 'string', 'max:500'],
            'symbology' => ['required', 'string', 'in:auto,GS1-128,EAN-13,CODE-128,QR,DataMatrix'],
            'warehouse_id' => ['nullable', 'uuid'],
        ];
    }
}
