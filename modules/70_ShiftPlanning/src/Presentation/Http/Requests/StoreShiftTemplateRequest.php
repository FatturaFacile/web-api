<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreShiftTemplateRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();
        $ignore = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('roster_shift_templates', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($ignore)],
            'name' => ['required', 'array'],
            'name.it' => ['required', 'string', 'max:120'],
            'name.de' => ['required', 'string', 'max:120'],
            'name.fr' => ['required', 'string', 'max:120'],
            'name.en' => ['required', 'string', 'max:120'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'night_surcharge_applicable' => ['nullable', 'boolean'],
            'is_on_call' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
