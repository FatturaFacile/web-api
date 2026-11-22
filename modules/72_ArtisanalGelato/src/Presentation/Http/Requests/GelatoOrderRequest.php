<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pre-order of a praline box, a gelato cake or a take-away tub, and the status change of an order.
 */
final class GelatoOrderRequest extends FormRequest
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
        $isStatus = $this->routeIs('gelato.orders.status');

        return [
            'status' => [$isStatus ? 'required' : 'nullable', Rule::in(['confirmed', 'ready', 'collected', 'cancelled'])],
            'kind' => [$isStatus ? 'nullable' : 'required', Rule::in(['praline_box', 'gelato_cake', 'takeaway_tub'])],
            'customer_name' => [$isStatus ? 'nullable' : 'required', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'components' => [$isStatus ? 'nullable' : 'required', 'array', 'min:1', 'max:100'],
            'components.*.recipe_id' => ['nullable', 'uuid'],
            'components.*.label' => ['nullable', 'string', 'max:160'],
            'components.*.grams' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'components.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'components.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'total_price' => [$isStatus ? 'nullable' : 'required', 'numeric', 'min:0', 'max:1000000'],
            'pickup_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
