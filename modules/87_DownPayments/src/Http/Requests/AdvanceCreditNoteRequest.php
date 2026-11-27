<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\DownPayments\Services\AdvanceCreditNoteService;

/**
 * A credit note against an advance invoice: everything still open, a gross amount, or the net of chosen VAT lines.
 */
final class AdvanceCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by the policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:'.AdvanceCreditNoteService::MODE_TOTAL.','.AdvanceCreditNoteService::MODE_AMOUNT.','.AdvanceCreditNoteService::MODE_LINES],
            'amount' => ['nullable', 'required_if:mode,amount', 'numeric', 'decimal:0,2', 'gt:0'],
            'lines' => ['nullable', 'required_if:mode,lines', 'array', 'min:1', 'max:20'],
            'lines.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.net' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
