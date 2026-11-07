<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

final class StoreProtocolEntryRequest extends FormRequest
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
            'direction' => ['required', Rule::in(['incoming', 'outgoing', 'internal'])],
            'registered_on' => ['sometimes', 'date'],
            'document_date' => ['sometimes', 'nullable', 'date'],
            'correspondent' => ['required', 'string', 'max:500'],
            'subject' => ['required', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'meeting_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_meetings')],
            'file' => ['sometimes', 'file', 'max:'.(int) config('associations.upload_max_kilobytes'), 'mimes:pdf,jpg,jpeg,png,doc,docx,odt,eml,msg,txt'],
        ];
    }
}
