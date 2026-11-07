<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\LotLevel;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class StoreStockLotRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'stock_item_id' => [$presence, 'uuid', $this->tenantExists('stock_items')],
            'lot_number' => [$presence, 'string', 'max:100'],
            'lot_level' => ['nullable', Rule::enum(LotLevel::class)],
            'supplier_lot_number' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'manufacturing_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date'],
            'received_at' => ['nullable', 'date'],
            'certification_scheme' => ['nullable', 'string', 'max:100'],
            'certification_reference' => ['nullable', 'string', 'max:150'],
            'certification_date' => ['nullable', 'date'],
        ];
    }
}
