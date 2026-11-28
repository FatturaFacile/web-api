<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\RecipientProfile;

final class InlDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', RecipientProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'in:pdf,xml,json'],
        ];
    }
}
