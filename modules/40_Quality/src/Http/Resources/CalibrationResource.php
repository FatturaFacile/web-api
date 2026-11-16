<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsCalibration;

/**
 * A calibration with its certificate and result.
 *
 * @mixin QmsCalibration
 */
final class CalibrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instrument_id' => $this->instrument_id,
            'result' => $this->result,
            'as_found_error' => $this->as_found_error,
            'as_left_error' => $this->as_left_error,
            'laboratory' => $this->laboratory,
            'certificate_number' => $this->certificate_number,
            'certificate_document_id' => $this->certificate_document_id,
            'performed_by' => $this->performed_by,
            'nonconformity_id' => $this->nonconformity_id,
            'notes' => $this->notes,
            'calibrated_on' => $this->calibrated_on->toDateString(),
            'next_due_on' => $this->next_due_on->toDateString(),
        ];
    }
}
