<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Quarterly LIPE communication (D.L. 193/2016): the periodic VAT settlements of a quarter, the generated XML file, its
 * schema validation, the reconciliation with the ledger and the signature and transmission status.
 *
 * @property array<string, string> $totals
 * @property list<string> $settlement_ids
 * @property int $year
 * @property int $quarter
 * @property string $status
 * @property list<string> $settlement_ids
 * @property array<string, string> $totals
 * @property string|null $file_name
 * @property string|null $xml_content
 * @property list<string>|null $xsd_errors
 * @property string $reconciliation_status
 * @property array<string, mixed>|null $reconciliation
 * @property string $signature_status
 * @property string|null $signature_digest
 * @property string|null $signer_fingerprint
 * @property CarbonImmutable|null $signed_at
 * @property string|null $transmission_protocol
 * @property CarbonImmutable|null $transmitted_at
 * @property string|null $rejection_reason
 */
final class LipeCommunication extends BaseEnterpriseModel
{
    protected $table = 'it_lipe_communications';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'settlement_ids' => 'array',
            'totals' => 'array',
            'xsd_errors' => 'array',
            'reconciliation' => 'array',
            'signed_at' => 'immutable_datetime',
            'transmitted_at' => 'immutable_datetime',
        ];
    }
}
