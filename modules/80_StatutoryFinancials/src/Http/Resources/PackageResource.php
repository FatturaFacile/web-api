<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Models\DepositPackage;

/**
 * XbrlAndPdfADepositPackage.
 *
 * @mixin DepositPackage
 */
final class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dossier_id' => $this->dossier_id,
            'generated_xbrl_instance_file_id' => $this->generated_xbrl_instance_file_id,
            'tebeni_validation_report' => $this->tebeni_validation_report,
            'validation_status' => $this->validation_status,
            'management_report_pdf_a_id' => $this->management_report_pdf_a_id,
            'shareholders_minutes_pdf_a_id' => $this->shareholders_minutes_pdf_a_id,
            'board_minutes_pdf_a_id' => $this->board_minutes_pdf_a_id,
            'dossier_pdf_a_id' => $this->dossier_pdf_a_id,
            'financial_ratios_crisi_impresa' => $this->financial_ratios_crisi_impresa,
            'exported_at' => $this->exported_at?->toIso8601String(),
        ];
    }
}
