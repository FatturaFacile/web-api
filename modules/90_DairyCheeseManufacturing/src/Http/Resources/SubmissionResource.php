<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;

/**
 * @mixin RegulatorySubmission
 */
final class SubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'authority' => $this->authority, 'period' => $this->period, 'status' => $this->status, 'format' => $this->format, 'record_count' => (int) $this->record_count,
            'checksum' => $this->checksum, 'generated_at' => $this->generated_at->toIso8601String(), 'submitted_at' => $this->submitted_at?->toIso8601String(), 'file_name' => $this->file_name,
            'validation_errors' => array_values((array) $this->validation_errors),
        ];
    }
}
