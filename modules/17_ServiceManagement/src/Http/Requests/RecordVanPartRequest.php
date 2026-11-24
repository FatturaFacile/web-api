<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class RecordVanPartRequest extends FormRequest
{
    use TenantScopedRules;

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
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'product_id' => ['required', 'uuid', $this->tenantOwns('products')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'lot_or_serial_id' => ['nullable', 'uuid'],
            'lot_number' => ['nullable', 'string', 'max:60'],
        ];
    }
}
