<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the batches of `/api/v1/accounting/payables/batches`, with the statuses of the web client.
 */
final class PayablesBatchIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'approved', 'rejected', 'executed', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The status of the module for the one of the client.
     */
    public function runStatus(): ?string
    {
        return match ((string) $this->input('status')) {
            '' => null,
            'pending_approval' => 'pending_cfo_approval',
            'approved' => 'authorized',
            default => (string) $this->input('status'),
        };
    }
}
