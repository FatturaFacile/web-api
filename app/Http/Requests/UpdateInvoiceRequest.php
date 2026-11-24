<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What can still change on a sales document after its creation: the notes, and the due date of a document not yet
 * issued (a proforma or a draft). The due date of an issued document is part of its ledger entry and payment schedule,
 * so the controller refuses it.
 */
class UpdateInvoiceRequest extends FormRequest
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
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
