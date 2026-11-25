<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\DebtCollection\Models\Notice;

final class CreateBatchRequest extends FormRequest
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
            'notice_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'notice_ids.*' => ['uuid'],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp', 'pec', 'registered_mail'])],
        ];
    }
}
