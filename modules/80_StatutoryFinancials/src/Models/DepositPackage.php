<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * XbrlAndPdfADepositPackage: the XBRL instance, its validation report, the PDF/A documents and the crisis indicators.
 *
 * @property string $dossier_id
 * @property string|null $generated_xbrl_instance_file_id
 * @property array<string, mixed>|null $tebeni_validation_report
 * @property string $validation_status
 * @property string|null $management_report_pdf_a_id
 * @property string|null $shareholders_minutes_pdf_a_id
 * @property string|null $board_minutes_pdf_a_id
 * @property string|null $dossier_pdf_a_id
 * @property array<string, mixed>|null $financial_ratios_crisi_impresa
 * @property Carbon|null $exported_at
 */
final class DepositPackage extends BaseEnterpriseModel
{
    protected $table = 'sfs_deposit_packages';

    protected function casts(): array
    {
        return [
            'tebeni_validation_report' => 'array',
            'financial_ratios_crisi_impresa' => 'array',
            'exported_at' => 'datetime',
        ];
    }
}
