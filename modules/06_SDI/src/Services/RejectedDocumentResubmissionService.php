<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Actions\Sdi\SendInvoiceToSdi;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * "Correct and resubmit" workflow after an NS: within five calendar days the corrected file is sent again keeping the
 * original number and date (Circular 13/E/2018), with a new ProgressivoInvio linked to the rejected document.
 */
final class RejectedDocumentResubmissionService
{
    public function __construct(
        private readonly ProgressivoInvioGenerator $progressive,
        private readonly SendInvoiceToSdi $sender,
    ) {}

    /**
     * @throws \DomainException when the document is not rejected or the five days are over
     */
    public function createRevision(FpaDocument $rejected): FpaDocument
    {
        $this->assertResubmittable($rejected);

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

    /**
     * Sends the corrected invoice again through the normal send path (SendInvoiceToSdi), inside the five days. The file
     * gets a new name from the shared progressive sequence, as SDI refuses a name it has already seen, and the new
     * envelope is linked to the rejected one. The invoice is the one now stored: correct it first, then resubmit.
     *
     * @throws \DomainException when the document is not rejected, has no invoice or the five days are over
     */
    public function resubmit(FpaDocument $rejected): FpaDocument
    {
        $this->assertResubmittable($rejected);

        $invoice = $rejected->invoice_id === null ? null : Invoice::withoutGlobalScopes()->where('tenant_id', $rejected->tenant_id)->find($rejected->invoice_id);

        if ($invoice === null) {
            throw new \DomainException('The rejected document has no invoice to resubmit.');
        }

        // The envelope was rejected by SDI: the invoice may be sent again whatever its metadata still says.
        $invoice->sdiMetadata()->update(['sdi_status' => 'rejected', 'transmission_claimed_at' => null]);
        $invoice->unsetRelation('sdiMetadata');

        $metadata = $this->sender->execute($invoice);

        return DB::transaction(function () use ($rejected, $metadata): FpaDocument {
            $revision = FpaDocument::withoutGlobalScopes()
                ->where('tenant_id', $rejected->tenant_id)
                ->where('invoice_id', $rejected->invoice_id)
                ->where('file_name', $metadata->file_name)
                ->firstOrFail();
            $revision->forceFill(['parent_fpa_document_id' => $rejected->id])->save();

            return $revision;
        });
    }

    private function assertResubmittable(FpaDocument $rejected): void
    {
        if ($rejected->status !== FpaStatus::Rejected) {
            throw new \DomainException('Only a rejected document can be resubmitted.');
        }

        if ($rejected->resubmission_deadline_at === null || now()->greaterThan($rejected->resubmission_deadline_at)) {
            throw new \DomainException('The resubmission deadline has passed.');
        }
    }
}
