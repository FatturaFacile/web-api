<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentVersionRequest extends FormRequest
{
    use ValidatesUploadContentLength;

    protected int $maxContentLengthBytes = 25 * 1024 * 1024;

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
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,gif,tif,tiff,doc,docx,xls,xlsx,csv,txt,xml,zip,eml,msg'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
