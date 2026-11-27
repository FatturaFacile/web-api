<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Confirm and cancel: no input, only the permission to change the order.
 */
final class OrderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = SubcontractingOrder::query()->whereKey($this->route('id'))->first();

        return $order instanceof SubcontractingOrder && ($this->user()?->can('update', $order) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
