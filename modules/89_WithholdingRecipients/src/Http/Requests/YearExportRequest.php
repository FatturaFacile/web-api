<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;

/**
 * Tax year of the annual CU / AVS export.
 */
final class YearExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', CuAutonomousRecord::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_year' => ['required', 'integer', 'between:2000,2100'],
        ];
    }
}
