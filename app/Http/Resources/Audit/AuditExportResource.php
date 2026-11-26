<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use Enterprise\AuditLedger\Models\AuditExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuditExport $resource
 */
final class AuditExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $export = $this->resource;

        return [
            'id' => $export->id,
            'status' => $export->status,
            'from' => $export->from_date->toDateString(),
            'to' => $export->to_date->toDateString(),
            'filters' => $export->filters ?? [],
            'entries_count' => $export->entries_count,
            'from_sequence' => $export->from_sequence,
            'to_sequence' => $export->to_sequence,
            'start_hash' => $export->start_hash,
            'end_hash' => $export->end_hash,
            'verification_outcome' => $export->verification_outcome,
            'sha256' => $export->sha256,
            'signature' => $export->signature,
            'error' => $export->status === AuditExport::STATUS_FAILED ? 'export_failed' : null,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'download_url' => $export->status === AuditExport::STATUS_COMPLETED ? url('/api/v1/settings/audit-logs/exports/'.$export->id.'/download') : null,
        ];
    }
}
