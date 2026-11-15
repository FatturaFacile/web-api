<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Quality\Models\QmsCalibration;

/**
 * The result of a calibration with the certificate. The next due date defaults to the calibration interval.
 */
final class CalibrationRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'calibrated_on' => ['required', 'date_format:Y-m-d'],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after:calibrated_on'],
            'result' => ['required', Rule::in(QmsCalibration::Results)],
            'as_found_error' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'as_left_error' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'laboratory' => ['nullable', 'string', 'max:160'],
            'certificate_number' => ['nullable', 'string', 'max:80'],
            'certificate_document_id' => ['nullable', 'uuid', $this->ownRow('dms_documents')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
