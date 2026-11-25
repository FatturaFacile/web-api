<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class GenerateNoticesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1', 'max:500'],
            'groups.*.customer_id' => ['required', 'uuid'],
            'groups.*.currency' => ['required', 'string', 'size:3'],
            'groups.*.level' => ['required', 'integer', 'between:0,3'],
            'groups.*.receivable_item_ids' => ['required', 'array', 'min:1'],
            'groups.*.receivable_item_ids.*' => ['uuid'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp', 'pec', 'registered_mail'])],
            'locale' => ['nullable', Rule::in(['it', 'en', 'de', 'fr'])],
            'post_charges' => ['nullable', 'boolean'],
            'dispatch' => ['nullable', 'boolean'],
        ];
    }
}
