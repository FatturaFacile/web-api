<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class MatchItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'counterpart_document_id' => ['required', 'uuid'],
        ];
    }
}
