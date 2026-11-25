<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class ReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['accettazione', 'consegna', 'mancata_consegna', 'non_accettazione'])],
            'message_id' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
