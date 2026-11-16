<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Services\AccountantArchiveBuilder;

/**
 * Request of an export: a dataset and an optional period. The accountant archive needs a period of at most one year.
 */
final class StoreExportJobRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $archive = $this->input('dataset') === 'accountant_archive';

        return [
            'dataset' => ['required', 'string', Rule::in(ExportJob::Datasets)],
            'from' => [$archive ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'to' => [$archive ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->input('dataset') === 'accountant_archive'
                && ! AccountantArchiveBuilder::periodFits(CarbonImmutable::parse($this->input('from')), CarbonImmutable::parse($this->input('to')))) {
                $validator->errors()->add('to', __('accounting.errors.period_max_one_year'));
            }
        }];
    }
}
