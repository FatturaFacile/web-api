<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, token-secured follow-up of the landing page: a text comment and/or a voice note (with the transcript the browser produced).
 */
final class SubmitFeedbackRequest extends FormRequest
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
            'comment_text' => ['nullable', 'string', 'max:4000'],
            'voice' => ['nullable', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/x-wav,video/webm', 'max:'.(int) config('customer_satisfaction.voice_max_kb')],
            'voice_duration_seconds' => ['nullable', 'integer', 'min:1', 'max:900'],
            'voice_transcript' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
