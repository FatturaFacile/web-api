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
 * Queued email informing the requester that their Purchase Requisition was blocked under hard-block budget policy.
 */
final class RequisitionBudgetBlockedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $requisitionId,
        public readonly string $requisitionNumber,
        public readonly string $requesterName,
        public readonly string $requestedAmount,
        public readonly string $residualAmount,
        public readonly string $overageAmount,
        public readonly string $currency,
        public readonly string $costCenterLabel,
        string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('purchase_requisition.mail.budget_blocked_subject', [
                'number' => $this->requisitionNumber,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        $greeting = e(__('purchase_requisition.mail.budget_blocked_greeting', [
            'requester' => $this->requesterName,
        ], $this->locale));

        $body = e(__('purchase_requisition.mail.budget_blocked_body', [
            'number' => $this->requisitionNumber,
            'amount' => $this->requestedAmount,
            'currency' => $this->currency,
            'cost_center' => $this->costCenterLabel,
        ], $this->locale));

        $numbers = e(__('purchase_requisition.mail.budget_blocked_numbers', [
            'residual' => $this->residualAmount,
            'requested' => $this->requestedAmount,
            'overage' => $this->overageAmount,
            'currency' => $this->currency,
        ], $this->locale));

        $outro = e(__('purchase_requisition.mail.budget_blocked_outro', [], $this->locale));

        return new Content(
            htmlString: "<p>{$greeting}</p><p>{$body}</p><p><strong>{$numbers}</strong></p><p>{$outro}</p>",
        );
    }
}
