<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class SellVehicleRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The tax rate is chosen by the user: margin taxation of used vehicles is not computed by the module.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'buyer_contact_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'sale_date' => ['required', 'date'],
            'sale_price' => ['required', 'decimal:0,2', 'gt:0'],
            'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'license_plate' => ['nullable', 'string', 'max:20'],
        ];
    }
}
