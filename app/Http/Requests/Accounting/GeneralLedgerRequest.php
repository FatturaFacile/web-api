<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\GeneralLedgerExportService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Period and filters of the general ledger preview and export.
 */
class GeneralLedgerRequest extends FormRequest
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
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
            'source' => ['sometimes', Rule::in([GeneralLedgerExportService::SOURCE_ALL, GeneralLedgerExportService::SOURCE_INVOICES, GeneralLedgerExportService::SOURCE_PAYMENTS, GeneralLedgerExportService::SOURCE_BANKING])],
            'date_format' => ['sometimes', Rule::in([GeneralLedgerExportService::DATE_ISO, GeneralLedgerExportService::DATE_EUROPEAN])],
            'format' => ['sometimes', Rule::in(['csv', 'json'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * The period is at most one year: `to_date` comes before `from_date` plus one year, so a calendar year fits.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! GeneralLedgerExportService::periodFits($this->from(), $this->to())) {
                    $validator->errors()->add('to_date', __('accounting.errors.period_max_one_year'));
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('from_date'));
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->input('to_date'));
    }

    public function source(): string
    {
        return (string) $this->input('source', GeneralLedgerExportService::SOURCE_ALL);
    }

    public function dateFormat(): string
    {
        return (string) $this->input('date_format', GeneralLedgerExportService::DATE_ISO);
    }
}
