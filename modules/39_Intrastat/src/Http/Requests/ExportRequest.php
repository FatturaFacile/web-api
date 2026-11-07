<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The data of the record di testa of the telematic file. Through Entratel the user code is always ZENT and the
 * progressivo sede always 001 (Agenzia delle Entrate, Allegato I alla determinazione 63336/RU del 7 maggio 2010).
 */
final class ExportRequest extends FormRequest
{
    use TenantScopedRules;

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
            'declaration_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'declaration_ids.*' => ['uuid', 'distinct', $this->tenantOwns('intrastat_declarations')],
            'channel' => ['required', 'in:entratel,customs'],
            'user_code' => ['required_if:channel,customs', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{4}$/'],
            'customs_section_code' => ['required', 'string', 'regex:/^\d{1,6}$/'],
            'sender_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'site_progressive' => ['required_if:channel,customs', 'nullable', 'integer', 'between:1,999'],
            'transmission_date' => ['nullable', 'date'],
            'sequence' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2}$/'],
        ];
    }
}
