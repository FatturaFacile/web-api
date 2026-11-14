<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\SignatureStatus;

/**
 * An assessment decision as issued by customs. Rows are append-only, also at database level.
 */
final class CustomsAssessmentDecision extends CustomsRecord
{
    use ProtectsRecords;

    public const null UPDATED_AT = null;

    protected $hidden = ['xml_path', 'pdf_path', 'envelope_path'];

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'Customs assessment decisions are strictly immutable and cannot be updated or deleted.';
    }

    protected function casts(): array
    {
        return [
            'document_type' => AssessmentDocumentType::class,
            'signature_status' => SignatureStatus::class,
            'signature_report' => 'array',
            'document_date' => 'date',
            'duty_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'vat_base_amount' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'amount_not_charged' => 'boolean',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<CustomsLedgerPosting, $this>
     */
    public function ledgerPosting(): HasOne
    {
        return $this->hasOne(CustomsLedgerPosting::class, 'assessment_decision_id');
    }
}
