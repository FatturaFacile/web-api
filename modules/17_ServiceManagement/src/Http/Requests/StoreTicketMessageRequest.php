<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Enums\TicketStatus;

final class StoreTicketMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:20000'],
            'visibility' => ['nullable', 'string', Rule::enum(MessageVisibility::class)],
            'status' => ['nullable', 'string', Rule::in(TicketStatus::settableValues())],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:'.(int) config('helpdesk.attachment_max_kb', 10240), 'mimes:jpeg,jpg,png,gif,webp,pdf,txt,csv,log,doc,docx,xls,xlsx,odt,ods,zip,eml'],
        ];
    }
}
