<?php

declare(strict_types=1);

namespace Modules\Intercompany\Listeners;

use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Services\MirrorPostingService;
use Modules\Intercompany\Services\PaymentSyncService;
use Modules\Intercompany\Services\SettingsService;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Throwable;

/**
 * Hooks of the mirror engine into the invoicing events. A posted invoice, debit note or credit note issued to a contact that
 * stands for another entity of a group (party link) is mirrored when the group has auto mirroring on; a payment of such an
 * invoice is carried to its mirror when payment sync is on. The listeners never break the posting of the invoice: a failure
 * is reported and the transaction is picked up again by the scan or by a manual retry.
 */
final readonly class IntercompanyDocumentListener
{
    public function __construct(
        private MirrorPostingService $mirror,
        private PaymentSyncService $payments,
        private SettingsService $settings,
    ) {}

    public function handlePosted(InvoicePostedEvent $event): void
    {
        $this->mirrorDocument($event->tenantId, $event->invoiceId, $event->customerId);
    }

    public function handleCreditNote(CreditNoteIssuedEvent $event): void
    {
        $this->mirrorDocument($event->tenantId, $event->creditNoteId, $event->customerId);
    }

    public function handlePaid(InvoicePaidEvent $event): void
    {
        try {
            $link = TransactionLink::withoutGlobalScopes()->where('source_tenant_id', $event->tenantId)->where('source_document_id', $event->invoiceId)->first();

            if ($link === null) {
                return;
            }

            $group = ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->find($link->group_id);

            if ($group !== null) {
                $this->payments->sync($group, $link, null, true);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function mirrorDocument(string $tenantId, string $documentId, ?string $contactId): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        try {
            $party = $this->mirror->linkFor($tenantId, $contactId);

            if ($party === null) {
                return;
            }

            $group = ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $party->tenant_id)->find($party->group_id);
            $invoice = SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($documentId);

            if ($group === null || $invoice === null || ! $this->settings->forGroup($group)->auto_mirror) {
                return;
            }

            $this->mirror->mirrorInvoice($group, $party, $invoice, null);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
