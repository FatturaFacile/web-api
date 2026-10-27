<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

class GenerateConsolidatedInvoiceRequest extends FormRequest
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
            'report_ids' => ['required', 'array', 'min:2'],
            'report_ids.*' => ['required', 'uuid', 'exists:service_reports,id'],
        ];
    }
}
