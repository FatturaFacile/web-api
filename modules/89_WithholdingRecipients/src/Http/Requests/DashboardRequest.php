<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Models\RecipientProfile;

final class DashboardRequest extends FormRequest
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
            'regime' => ['nullable', Rule::enum(RecipientRegime::class)],
            'jurisdiction' => ['nullable', Rule::enum(Jurisdiction::class)],
            'cu_causal' => ['nullable', Rule::in((array) config('withholding_recipients.cu_causals'))],
            'search' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'only_alerts' => ['nullable', 'boolean'],
        ];
    }
}
