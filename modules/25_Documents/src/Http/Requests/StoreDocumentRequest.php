<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Documents\Http\Requests\Concerns\ValidatesDocumentFields;

/**
 * Validates the input of the store document operation (authorization is decided by module.access).
 */
final class StoreDocumentRequest extends FormRequest
{
    use ValidatesDocumentFields, ValidatesUploadContentLength;

    protected int $maxContentLengthBytes = 25 * 1024 * 1024;

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
            ...$this->documentFieldRules(),
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,gif,tif,tiff,doc,docx,xls,xlsx,csv,txt,xml,zip,eml,msg'],
            'comment' => ['nullable', 'string', 'max:500'],
            'allow_duplicate' => ['nullable', 'boolean'],
        ];
    }
}
