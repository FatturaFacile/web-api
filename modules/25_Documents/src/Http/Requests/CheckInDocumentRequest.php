<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the document check-in: the edited file is optional (access is decided by module.access).
 */
final class CheckInDocumentRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
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
            'file' => ['nullable', 'file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,gif,tif,tiff,doc,docx,xls,xlsx,csv,txt,xml,zip,eml,msg'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
