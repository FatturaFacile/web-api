<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks an open item as disputed, or clears the dispute.
 */
final class DisputeReceivableRequest extends FormRequest
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
            'disputed' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:disputed,true', 'string', 'max:500'],
        ];
    }
}
