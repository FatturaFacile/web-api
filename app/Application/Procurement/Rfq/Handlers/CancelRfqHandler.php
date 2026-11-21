<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\CancelRfqCommand;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Infrastructure\Procurement\Rfq\Mail\RfqCancelledMail;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Support\Decimal;

/**
 * Cancels a draft or open RFQ, releases locked Mod 044 PR line quantities, invalidates invitation tokens,
 * and dispatches cancellation notifications to invited vendors.
 */
final readonly class CancelRfqHandler
{
    public function __construct(
        private RequisitionSourcingAdapterInterface $requisitions,
        private ProcurementAuditLedgerService $auditLedger,
        private RfqSupplierRecipientResolver $recipientResolver,
    ) {}

    public function handle(CancelRfqCommand $command): RequestForQuotation
    {
        /** @var list<array{email: string, locale: string, supplier_name: string}> $notifications */
        $notifications = [];
        $wasPublished = false;

        $rfq = DB::transaction(function () use ($command, &$notifications, &$wasPublished): RequestForQuotation {
            /** @var RequestForQuotation $locked */
            $locked = RequestForQuotation::query()
                ->with(['lines', 'invitations.supplier'])
                ->whereKey($command->rfqId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->statusEnum()->canBeCancelled()) {
                throw new ProcurementRuleViolation("An RFQ in status {$locked->status} cannot be cancelled.", 'status');
            }

            $previousStatus = $locked->statusEnum();
            $wasPublished = $previousStatus !== RfqStatus::Draft;

            $releaseQuantities = [];
            foreach ($locked->lines as $line) {
                if ($line->purchase_requisition_line_id !== null) {
                    $prLineId = (string) $line->purchase_requisition_line_id;
                    $releaseQuantities[$prLineId] = Decimal::add($releaseQuantities[$prLineId] ?? '0.0000', (string) $line->quantity, 4);
                }
            }

            if ($releaseQuantities !== []) {
                $this->requisitions->releaseLinesFromRfq((string) $locked->tenant_id, $releaseQuantities, (string) $locked->id);
            } elseif ($locked->purchase_requisition_id !== null) {
                // Legacy header-only PR release
                PurchaseRequisition::query()
                    ->whereKey($locked->purchase_requisition_id)
                    ->where('status', PurchaseRequisitionStatus::Sourcing->value)
                    ->update(['status' => PurchaseRequisitionStatus::Approved->value]);
            }

            $now = CarbonImmutable::now('UTC');
            $locked->update([
                'status' => RfqStatus::Cancelled->value,
            ]);

            RfqSupplierInvitation::query()
                ->where('rfq_id', $locked->id)
                ->update(['token_expires_at' => $now]);

            if ($wasPublished) {
                $tenant = Tenant::query()->find($locked->tenant_id);
                foreach ($locked->invitations as $invitation) {
                    if ($invitation->statusEnum() === InvitationStatus::Declined || $invitation->supplier === null) {
                        continue;
                    }

                    $recipient = $this->recipientResolver->resolve($invitation->supplier, $tenant);
                    if ($recipient['email'] !== null) {
                        $notifications[] = [
                            'email' => $recipient['email'],
                            'locale' => $recipient['locale'],
                            'supplier_name' => $recipient['supplier_name'],
                        ];
                    }
                }
            }

            $this->auditLedger->record(
                action: 'RFQ_CANCELLED',
                model: $locked,
                payloadBefore: ['status' => $previousStatus->value],
                payloadAfter: ['status' => RfqStatus::Cancelled->value, 'reason' => $command->reason]
            );

            return $locked->fresh(['lines', 'attachments', 'invitations.supplier', 'buyer']) ?? $locked;
        });

        if ($wasPublished && $notifications !== []) {
            $tenant = Tenant::query()->find($rfq->tenant_id);
            $buyerCompanyName = (string) ($tenant?->legal_name ?: $tenant?->name ?: config('app.name', 'FatturaFacile'));
            $reason = $command->reason;

            DB::afterCommit(static function () use ($notifications, $rfq, $buyerCompanyName, $reason): void {
                foreach ($notifications as $notify) {
                    Mail::to($notify['email'])
                        ->locale($notify['locale'])
                        ->queue(new RfqCancelledMail(
                            rfqId: (string) $rfq->id,
                            rfqNumber: (string) $rfq->rfq_number,
                            rfqTitle: (string) $rfq->title,
                            buyerCompanyName: $buyerCompanyName,
                            supplierName: $notify['supplier_name'],
                            reason: $reason,
                            mailLocale: $notify['locale'],
                        ));
                }
            });
        }

        return $rfq;
    }
}
