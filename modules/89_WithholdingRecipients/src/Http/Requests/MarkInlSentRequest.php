<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;

final class MarkInlSentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', OccasionalInlNotification::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'protocol_reference' => ['nullable', 'string', 'max:120'],
            'sent_at' => ['nullable', 'date'],
        ];
    }
}
