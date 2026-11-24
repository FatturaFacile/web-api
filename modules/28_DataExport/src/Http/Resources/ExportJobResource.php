<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataExport\Models\ExportJob;

/**
 * API representation of an export of the history. The storage path never leaves the API.
 *
 * @mixin ExportJob
 */
final class ExportJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'dataset' => $this->dataset,
            'status' => $this->status,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'file_name' => $this->file_name,
            'size_bytes' => $this->size_bytes,
            'row_count' => $this->row_count,
            'error' => $this->error,
            'schedule_id' => $this->schedule_id,
            'is_downloadable' => $this->isDownloadable(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
