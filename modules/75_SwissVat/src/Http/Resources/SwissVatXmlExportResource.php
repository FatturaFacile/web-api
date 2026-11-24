<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissVat\Models\SwissVatXmlExport;

/**
 * @mixin SwissVatXmlExport
 */
final class SwissVatXmlExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'swiss_vat_return_id' => (string) $this->swiss_vat_return_id,
            'accounting_method' => $this->accounting_method,
            'period_label' => $this->period_label,
            'schema_version' => $this->schema_version,
            'file_name' => $this->file_name,
            'sha256_checksum' => $this->sha256_checksum,
            'xsd_valid' => (bool) $this->xsd_valid,
            'quadratura_passed' => (bool) $this->quadratura_passed,
            'validation_errors' => $this->validation_errors ?? [],
            'exported_at' => $this->exported_at->toIso8601String(),
        ];
    }
}
