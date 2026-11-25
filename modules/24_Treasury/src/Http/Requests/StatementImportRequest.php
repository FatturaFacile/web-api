<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload of a bank statement: camt.053, camt.054, CBI text or CSV. A CSV needs either a saved profile or an inline
 * mapping (delimiter, formats and the columns that carry date, amount and description).
 */
final class StatementImportRequest extends FormRequest
{
    use ValidatesUploadContentLength;

    protected function maxContentLengthBytes(): int
    {
        return 10 * 1024 * 1024;
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $csv = fn (): bool => $this->input('format') === 'csv';

        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:xml,txt,cbi,csv,dat'],
            'format' => ['required', Rule::in(['auto', 'camt053', 'camt054', 'cbi', 'csv'])],
            'auto_reconcile' => ['sometimes', 'boolean'],
            'csv_profile_id' => ['nullable', 'uuid'],
            'csv' => ['nullable', 'array'],
            'csv.account_iban' => [Rule::requiredIf(fn (): bool => $csv() && ! $this->filled('csv_profile_id')), 'string', 'max:34'],
            'csv.currency' => ['nullable', 'string', 'size:3'],
            'csv.delimiter' => ['nullable', 'string', 'size:1'],
            'csv.has_header' => ['nullable', 'boolean'],
            'csv.date_format' => ['nullable', 'string', 'max:30'],
            'csv.decimal_separator' => ['nullable', 'string', 'size:1'],
            'csv.thousands_separator' => ['nullable', 'string', 'max:1'],
            'csv.columns' => [Rule::requiredIf(fn (): bool => $csv() && ! $this->filled('csv_profile_id')), 'array'],
            'csv.columns.*' => ['nullable'],
        ];
    }
}
