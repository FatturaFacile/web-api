<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\DeclineRfqInvitationCommand;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

/**
 * Allows an invited supplier to decline participation in an open RFQ.
 */
final readonly class DeclineRfqInvitationHandler
{
    public function __construct(
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(DeclineRfqInvitationCommand $command): RfqSupplierInvitation
    {
        return DB::transaction(function () use ($command): RfqSupplierInvitation {
            /** @var RfqSupplierInvitation $invitation */
            $invitation = RfqSupplierInvitation::query()
                ->whereKey($command->invitationId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var RequestForQuotation $rfq */
            $rfq = RequestForQuotation::query()->whereKey($invitation->rfq_id)->lockForUpdate()->firstOrFail();

            if (! $rfq->statusEnum()->isOpenForBidding()) {
                throw new ProcurementRuleViolation('This RFQ can no longer be declined.', 'status');
            }

            if (! $invitation->statusEnum()->canDecline()) {
                throw new ProcurementRuleViolation('This invitation can no longer be declined.', 'invitation');
            }

            $now = CarbonImmutable::now('UTC');
            $invitation->update([
                'status' => InvitationStatus::Declined->value,
                'declined_reason' => $command->reason,
                'responded_at' => $now,
            ]);

            $this->auditLedger->record(
                action: 'RFQ_INVITATION_DECLINED',
                model: $invitation,
                payloadBefore: null,
                payloadAfter: [
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $invitation->supplier_id,
                    'declined_reason' => $command->reason,
                ]
            );

            return $invitation->fresh('supplier') ?? $invitation;
        });
    }
}
