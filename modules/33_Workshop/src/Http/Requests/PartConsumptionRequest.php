<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reserving a part of stock for a folder.
 */
final class PartConsumptionRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
            'product_id' => ['required', 'uuid', $this->tenantExists('products')],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'folder_line_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('workshop_folder_lines')],
        ];
    }
}
