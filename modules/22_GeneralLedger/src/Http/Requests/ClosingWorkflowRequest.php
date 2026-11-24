<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\LedgerBook;

/**
 * The ledger book (01 statutory, 02 tax, 03 ifrs) of a year-end closing workflow request; statutory when not given.
 */
final class ClosingWorkflowRequest extends FormRequest
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
            'ledger' => ['nullable', 'string', Rule::in(LedgerBook::accepted())],
        ];
    }

    public function book(): LedgerBook
    {
        $ledger = $this->validated('ledger');

        return LedgerBook::resolve(is_string($ledger) ? $ledger : null);
    }
}
