<?php

declare(strict_types=1);

namespace App\Http\Requests\SwissQrBill;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\SwissQrBill\QrBillPdfOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateQrBillPdfRequest extends FormRequest
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
            'invoice_id' => ['required', 'uuid', $this->tenantExists('invoices')],
            'mode' => ['nullable', Rule::in(QrBillPdfOptions::MODES)],
            'perforation' => ['nullable', Rule::in(QrBillPdfOptions::PERFORATIONS)],
            'language' => ['nullable', Rule::in(QrBillPdfOptions::LANGUAGES)],
        ];
    }
}
