<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;

/**
 * Public, token-secured score of the landing page or of the WhatsApp interactive reply (no login).
 */
final class SubmitScoreRequest extends FormRequest
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
            'score' => ['required', 'integer', 'min:0', 'max:10'],
            'submitted_via' => ['nullable', Rule::in([SubmittedVia::LandingPage->value, SubmittedVia::WhatsappReply->value])],
        ];
    }
}
