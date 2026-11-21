<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\PublishAndDispatchRfqCommand;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Infrastructure\Procurement\Rfq\Mail\RfqInvitationMail;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Services\ProcurementService;

/**
 * Publishes a draft RFQ, generates cryptographically secure 256-bit invitation tokens (storing only SHA-256 hashes),
 * transitions the RFQ to BiddingOpen, and queues supplier invitation emails after commit.
 */
final readonly class PublishAndDispatchRfqHandler
{
    public function __construct(
        private ProcurementService $procurement,
        private ProcurementAuditLedgerService $auditLedger,
        private RfqSupplierRecipientResolver $recipientResolver,
    ) {}

    public function handle(PublishAndDispatchRfqCommand $command): RequestForQuotation
    {
        /** @var list<array{email: string, locale: string, supplier_name: string, token: string}> $dispatches */
        $dispatches = [];

        $rfq = DB::transaction(function () use ($command, &$dispatches): RequestForQuotation {
            /** @var RequestForQuotation $locked */
            $locked = RequestForQuotation::query()
                ->with(['lines', 'invitations.supplier'])
                ->whereKey($command->rfqId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->statusEnum()->canBePublished()) {
                throw new ProcurementRuleViolation("An RFQ in status {$locked->status} cannot be published.", 'status');
            }

            if ($locked->lines->isEmpty()) {
                throw new ProcurementRuleViolation('At least one line item is required before publishing an RFQ.', 'lines');
            }

            if ($locked->invitations->isEmpty()) {
                throw new ProcurementRuleViolation('At least one qualified supplier must be invited before publishing an RFQ.', 'supplier_ids');
            }

            $deadline = $locked->effectiveDeadline();
            if ($deadline->lte(CarbonImmutable::now('UTC'))) {
                throw new ProcurementRuleViolation('The submission deadline must be in the future.', 'submission_deadline');
            }

            $tenant = Tenant::query()->find($locked->tenant_id);
            $now = CarbonImmutable::now('UTC');

            foreach ($locked->invitations as $invitation) {
                /** @var RfqSupplierInvitation $invitation */
                /** @var Supplier|null $supplier */
                $supplier = $invitation->supplier;
                if ($supplier === null) {
                    throw new ProcurementRuleViolation('Invited supplier record is missing.', 'supplier_ids');
                }

                $this->procurement->assertSupplierCanOrder($supplier);

                $plaintextToken = bin2hex(random_bytes(32));
                $invitation->update([
                    'status' => InvitationStatus::Sent->value,
                    'access_token_hash' => hash('sha256', $plaintextToken),
                    'token_expires_at' => $deadline,
                    'invited_at' => $now,
                ]);

                $recipient = $this->recipientResolver->resolve($supplier, $tenant);
                if ($recipient['email'] !== null) {
                    $dispatches[] = [
                        'email' => $recipient['email'],
                        'locale' => $recipient['locale'],
                        'supplier_name' => $recipient['supplier_name'],
                        'token' => $plaintextToken,
                    ];
                }
            }

            $locked->update([
                'status' => RfqStatus::BiddingOpen->value,
                'issue_date' => $now->toDateString(),
                'submission_deadline' => $deadline,
                'response_deadline' => $deadline->toDateString(),
            ]);

            $this->auditLedger->record(
                action: 'RFQ_PUBLISHED',
                model: $locked,
                payloadBefore: ['status' => RfqStatus::Draft->value],
                payloadAfter: [
                    'status' => RfqStatus::BiddingOpen->value,
                    'invited_suppliers' => $locked->invitations->count(),
                    'submission_deadline' => $deadline->toIso8601String(),
                ]
            );

            return $locked->fresh(['lines', 'attachments', 'invitations.supplier', 'buyer']) ?? $locked;
        });

        $tenant = Tenant::query()->find($rfq->tenant_id);
        $buyerCompanyName = (string) ($tenant?->legal_name ?: $tenant?->name ?: config('app.name', 'FatturaFacile'));
        $frontendBase = rtrim((string) config('app.frontend_url', 'https://localhost:4200'), '/');
        $deadlineIso = $rfq->effectiveDeadline()->toIso8601String();

        DB::afterCommit(static function () use ($dispatches, $rfq, $buyerCompanyName, $frontendBase, $deadlineIso): void {
            foreach ($dispatches as $dispatch) {
                $portalUrl = "{$frontendBase}/supplier-bid/{$dispatch['token']}";
                Mail::to($dispatch['email'])
                    ->locale($dispatch['locale'])
                    ->queue(new RfqInvitationMail(
                        rfqId: (string) $rfq->id,
                        rfqNumber: (string) $rfq->rfq_number,
                        rfqTitle: (string) $rfq->title,
                        buyerCompanyName: $buyerCompanyName,
                        supplierName: $dispatch['supplier_name'],
                        submissionDeadlineIso: $deadlineIso,
                        portalUrl: $portalUrl,
                        token: $dispatch['token'],
                        mailLocale: $dispatch['locale'],
                    ));
            }
        });

        return $rfq;
    }
}
