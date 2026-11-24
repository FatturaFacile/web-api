<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A single document uploaded to the private disk: meeting attachments, receipts of expenses, contracts.
 */
final class StoreFileRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.(int) config('associations.upload_max_kilobytes'), 'mimes:pdf,jpg,jpeg,png,doc,docx,odt,xlsx,ods,txt'],
        ];
    }
}
