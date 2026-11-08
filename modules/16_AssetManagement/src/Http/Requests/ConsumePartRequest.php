<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a spare part issued to a work order.
 */
final class ConsumePartRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
     */
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
            'product_id' => ['required', 'uuid', $this->tenantOwns('products')],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999'],
        ];
    }
}
