<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Services\AccrualReconciliationExporter;

final class AccrualReconciliationExportRequest extends AccrualSummaryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['nullable', 'string', Rule::in(AccrualReconciliationExporter::Formats)],
        ];
    }
}
