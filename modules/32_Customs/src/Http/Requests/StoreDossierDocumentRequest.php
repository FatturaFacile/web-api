<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDossierDocumentRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,xml,jpg,jpeg,png,eml,msg,txt,xlsx,docx', 'max:20480'],
            'document_kind' => ['required', 'string', 'in:invoice,delivery_note,loading_list,email,other'],
        ];
    }
}
