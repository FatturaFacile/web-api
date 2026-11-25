<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;

/**
 * A document of the company qualification vault (CompanyQualificationVaultDocument): SOA attestation, DURC, visura,
 * ISO certificates, Swiss social-security and debt-enforcement proofs.
 *
 * @property VaultDocType $doc_type
 * @property string|null $title
 * @property string|null $document_number
 * @property string|null $issuing_body
 * @property list<array{category: string, classification: string}>|null $soa_categories_classifications
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 * @property Carbon|null $intermediate_triennial_check_date
 * @property bool $fvoe20_synced
 * @property Carbon|null $fvoe20_synced_at
 * @property string|null $numeric_value
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_sha256
 * @property VerificationStatus $verification_status
 * @property string|null $renewed_from_id
 * @property string|null $notes
 */
final class QualificationVaultDocument extends BaseEnterpriseModel
{
    protected $table = 'tnd_vault_documents';

    protected function casts(): array
    {
        return [
            'doc_type' => VaultDocType::class,
            'verification_status' => VerificationStatus::class,
            'soa_categories_classifications' => 'array',
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'intermediate_triennial_check_date' => 'date',
            'fvoe20_synced' => 'boolean',
            'fvoe20_synced_at' => 'datetime',
            'numeric_value' => 'decimal:2',
        ];
    }
}
