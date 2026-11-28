<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;

final class SaveCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', AgentReducedWithholdingCert::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'received_on' => ['required', 'date'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'pec_message_id' => ['nullable', 'string', 'max:200'],
            'pec_archive_reference' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
