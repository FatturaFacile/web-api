<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
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
            'mirror_mode' => ['required', Rule::in(['draft', 'confirmed'])],
            'auto_mirror' => ['required', 'boolean'],
            'sync_payments' => ['required', 'boolean'],
            'netting_enabled' => ['required', 'boolean'],
            'amount_tolerance' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'date_tolerance_days' => ['required', 'integer', 'min:0', 'max:90'],
            'default_standard' => ['required', Rule::in(['fer30', 'ifrs10', 'oic17'])],
        ];
    }
}
