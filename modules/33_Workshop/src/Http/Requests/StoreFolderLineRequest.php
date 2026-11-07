<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workshop\Models\WorkshopFolderLine;

final class StoreFolderLineRequest extends FormRequest
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
            'line_type' => ['required', Rule::in(WorkshopFolderLine::Types)],
            'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            'discount_percentage' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'technician_user_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }
}
