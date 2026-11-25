<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCsvProfileRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('statement_csv_profiles', 'name')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'account_iban' => ['nullable', 'string', 'max:34'],
            'currency' => ['required', 'string', 'size:3'],
            'delimiter' => ['required', 'string', 'size:1'],
            'has_header' => ['required', 'boolean'],
            'date_format' => ['required', 'string', 'max:30'],
            'decimal_separator' => ['required', 'string', 'size:1'],
            'thousands_separator' => ['nullable', 'string', 'max:1'],
            'columns' => ['required', 'array'],
            'columns.booking_date' => ['required'],
            'columns.*' => ['nullable'],
        ];
    }
}
