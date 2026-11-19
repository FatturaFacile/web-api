<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class StorePortalTicketRequest extends FormRequest
{
    public const array KINDS = ['inquiry', 'invoice_dispute', 'defective_goods'];

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
            'kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'invoice_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'ddt_id' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,txt,csv,xlsx,docx'],
        ];
    }
}
