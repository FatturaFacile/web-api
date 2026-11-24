<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The decision of the CFO on a batch: the reason is optional when approving and required when rejecting.
 */
final class PayablesBatchDecisionRequest extends FormRequest
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
        return ['reason' => [$this->routeIs('accounts-payable.payables.batches.reject') ? 'required' : 'nullable', 'string', 'min:3', 'max:1000']];
    }
}
