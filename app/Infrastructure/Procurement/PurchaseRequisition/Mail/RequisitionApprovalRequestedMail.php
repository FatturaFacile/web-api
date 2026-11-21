<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued email notifying an approver of a Purchase Requisition step with single-use signed 1-click links.
 */
final class RequisitionApprovalRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requisitionId,
        public readonly string $requisitionNumber,
        public readonly string $requesterName,
        public readonly string $approverName,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $costCenterLabel,
        public readonly string $requestedDeliveryDate,
        public readonly string $urgency,
        public readonly int $stepLevel,
        public readonly string $approveToken,
        public readonly string $rejectToken,
        public readonly string $approveUrl,
        public readonly string $rejectUrl,
        public readonly string $expiresAtIso,
        public readonly bool $budgetWarning = false,
        public readonly string $overageAmount = '0.00',
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('purchase_requisition.mail.approval_requested_subject', [
                'level' => $this->stepLevel,
                'number' => $this->requisitionNumber,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('purchase_requisition.mail.approval_requested_greeting', [
            'approver' => $this->approverName,
        ], $this->locale));

        $intro = e(__('purchase_requisition.mail.approval_requested_intro', [
            'requester' => $this->requesterName,
            'number' => $this->requisitionNumber,
            'amount' => $this->totalAmount,
            'currency' => $this->currency,
            'level' => $this->stepLevel,
        ], $this->locale));

        $details = e(__('purchase_requisition.mail.approval_requested_details', [
            'cost_center' => $this->costCenterLabel,
            'delivery_date' => $this->requestedDeliveryDate,
            'urgency' => $this->urgency,
        ], $this->locale));

        $warningHtml = '';
        if ($this->budgetWarning) {
            $warningText = e(__('purchase_requisition.mail.approval_requested_budget_warning', [
                'overage' => $this->overageAmount,
                'currency' => $this->currency,
            ], $this->locale));
            $warningHtml = "<p><strong>{$warningText}</strong></p>";
        }

        $approveCta = e(__('purchase_requisition.mail.approval_requested_approve_cta', [], $this->locale));
        $rejectCta = e(__('purchase_requisition.mail.approval_requested_reject_cta', [], $this->locale));
        $expiryNote = e(__('purchase_requisition.mail.approval_requested_expiry_note', [
            'expires_at' => $this->expiresAtIso,
        ], $this->locale));

        $approveHref = e($this->approveUrl);
        $rejectHref = e($this->rejectUrl);

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$intro}</p><p>{$details}</p>{$warningHtml}<p><a href=\"{$approveHref}\">{$approveCta}</a> | <a href=\"{$rejectHref}\">{$rejectCta}</a></p><p>{$expiryNote}</p>",
        );
    }
}
