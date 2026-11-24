<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks a close task done, waived (with the reason in the note) or open again.
 */
final class UpdatePeriodCloseTaskStatusRequest extends FormRequest
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
            'status' => ['required', 'in:open,done,waived'],
            'note' => ['nullable', 'required_if:status,waived', 'string', 'max:1000'],
        ];
    }
}
