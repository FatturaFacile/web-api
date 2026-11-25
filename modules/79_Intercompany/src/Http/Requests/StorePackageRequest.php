<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePackageRequest extends FormRequest
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
            'standard' => ['required', Rule::in(['fer30', 'ifrs10', 'oic17'])],
        ];
    }
}
