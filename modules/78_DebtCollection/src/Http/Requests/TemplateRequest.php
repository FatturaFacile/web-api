<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Models\Notice;

final class TemplateRequest extends FormRequest
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
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ];
    }
}
