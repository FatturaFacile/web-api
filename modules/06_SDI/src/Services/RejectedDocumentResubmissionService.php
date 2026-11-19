<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * "Correct and resubmit" workflow after an NS: within five calendar days the corrected file is sent again keeping the
 * original number and date (Circular 13/E/2018), with a new ProgressivoInvio linked to the rejected document.
 */
final class RejectedDocumentResubmissionService
{
    public function __construct(private readonly ProgressivoInvioGenerator $progressive) {}

    /**
     * @throws \DomainException when the document is not rejected or the five days are over
     */
    public function createRevision(FpaDocument $rejected): FpaDocument
    {
        if ($rejected->status !== FpaStatus::Rejected) {
            throw new \DomainException('Only a rejected document can be resubmitted.');
        }

        if ($rejected->resubmission_deadline_at === null || now()->greaterThan($rejected->resubmission_deadline_at)) {
            throw new \DomainException('The resubmission deadline has passed.');
        }

        $next = $this->progressive->next($rejected->tenant, str_ends_with((string) $rejected->file_name, '.p7m') ? 'xml.p7m' : 'xml');

        return FpaDocument::withoutGlobalScopes()->create([
            'tenant_id' => $rejected->tenant_id,
            'invoice_id' => $rejected->invoice_id,
            'parent_fpa_document_id' => $rejected->id,
            'direction' => $rejected->direction,
            'format_transmission' => $rejected->format_transmission,
            'document_type' => $rejected->document_type,
            'document_number' => $rejected->document_number,
            'document_date' => $rejected->document_date,
            'transmission_progressive' => $next['progressive'],
            'file_name' => $next['file_name'],
            'recipient_code' => $rejected->recipient_code,
            'recipient_pec' => $rejected->recipient_pec,
            'status' => FpaStatus::Draft,
        ]);
    }
}
