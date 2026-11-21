<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parsed inbound message of the mail gateway. The HMAC signature of the raw body was verified before this runs.
 */
final class InboundEmailRequest extends FormRequest
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
            'to' => ['required', 'string', 'max:255'],
            'from' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'message_id' => ['required', 'string', 'max:255'],
            'attachments' => ['required', 'array', 'min:1', 'max:20'],
            'attachments.*.filename' => ['required', 'string', 'max:255'],
            'attachments.*.content_type' => ['nullable', 'string', 'max:100'],
            'attachments.*.content_base64' => ['required', 'string'],
        ];
    }
}
