<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\DdtCausale;

final class ReturnRmaRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'returned_at' => ['nullable', 'date'],
            'ddt_causale_return' => ['nullable', 'string', Rule::enum(DdtCausale::class)],
            'vat_exempt_art15_dpr633' => ['nullable', 'boolean'],
        ];
    }
}
