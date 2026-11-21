<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Generates the fiscal document of an existing invoice through the strategy of the given country. */
final class GenerateFiscalDocumentRequest extends FormRequest
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
            'country' => ['required', Rule::in(['IT'])],
            'invoice_id' => ['required', 'uuid'],
        ];
    }
}
