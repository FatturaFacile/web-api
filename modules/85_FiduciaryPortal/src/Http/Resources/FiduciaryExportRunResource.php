<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FiduciaryExportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'fiscal_year_id' => $this->fiscal_year_id ? (string) $this->fiscal_year_id : null,
            'fiscal_period_id' => $this->fiscal_period_id ? (string) $this->fiscal_period_id : null,
            'format_code' => (string) $this->format_code,
            'jurisdiction' => (string) $this->jurisdiction,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'only_verified' => (bool) $this->only_verified,
            'apply_transcoding' => (bool) $this->apply_transcoding,
            'entries_count' => (int) $this->entries_count,
            'lines_count' => (int) $this->lines_count,
            'unmapped_accounts_count' => (int) $this->unmapped_accounts_count,
            'total_debit' => (string) $this->total_debit,
            'total_credit' => (string) $this->total_credit,
            'currency' => (string) $this->currency,
            'file_name' => (string) $this->file_name,
            'mime_type' => (string) $this->mime_type,
            'diagnostics' => $this->diagnostics_json,
            'exported_by' => $this->exported_by ? (string) $this->exported_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
