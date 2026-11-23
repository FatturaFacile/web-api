<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\PurchaseOrderDispatchChannel;

/**
 * Transmission of a purchase order to the supplier through a channel.
 */
final class DispatchPurchaseOrderRequest extends FormRequest
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
        return [
            'channel' => ['required', Rule::in(PurchaseOrderDispatchChannel::values())],
            'recipient' => ['nullable', 'string', 'max:190'],
        ];
    }
}
