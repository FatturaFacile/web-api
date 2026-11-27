<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;

final class DeliverActiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = SubcontractingOrder::query()->whereKey($this->route('id'))->first();

        return $order instanceof SubcontractingOrder && ($this->user()?->can('dispatch', $order) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'return_unused_materials' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
