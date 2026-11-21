<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\ExtendRfqDeadlineCommand;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Infrastructure\Procurement\Rfq\Mail\RfqDeadlineExtendedMail;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

/**
 * Extends the submission deadline of an RFQ, extends invitation token expiry, and notifies invited suppliers.
 */
final readonly class ExtendRfqDeadlineHandler
{
    public function __construct(
        private ProcurementAuditLedgerService $auditLedger,
        private RfqSupplierRecipientResolver $recipientResolver,
    ) {}

    public function handle(ExtendRfqDeadlineCommand $command): RequestForQuotation
    {
        /** @var list<array{email: string, locale: string, supplier_name: string}> $notifications */
        $notifications = [];
        $shouldNotify = false;

        $rfq = DB::transaction(function () use ($command, &$notifications, &$shouldNotify): RequestForQuotation {
            /** @var RequestForQuotation $locked */
            $locked = RequestForQuotation::query()
                ->with('invitations.supplier')
                ->whereKey($command->rfqId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->statusEnum()->canExtendDeadline()) {
                throw new ProcurementRuleViolation("An RFQ in status {$locked->status} cannot extend its deadline.", 'status');
            }

            $newDeadline = $this->parseDeadline($command->newSubmissionDeadline);
            $now = CarbonImmutable::now('UTC');

            if ($newDeadline->lte($now)) {
                throw new ProcurementRuleViolation('The new submission deadline must be in the future.', 'submission_deadline');
            }

            if ($newDeadline->lte($locked->effectiveDeadline())) {
                throw new ProcurementRuleViolation('The new submission deadline must be later than the current deadline.', 'submission_deadline');
            }

            $previousDeadline = $locked->effectiveDeadline()->toIso8601String();
            $wasOpen = $locked->statusEnum() !== RfqStatus::Draft;

            $newStatus = in_array($locked->statusEnum(), [RfqStatus::UnderEvaluation, RfqStatus::Expired], true)
                ? RfqStatus::BiddingOpen->value
                : $locked->status;

            $locked->update([
                'status' => $newStatus,
                'submission_deadline' => $newDeadline,
                'response_deadline' => $newDeadline->toDateString(),
            ]);

            RfqSupplierInvitation::query()
                ->where('rfq_id', $locked->id)
                ->where('status', '!=', InvitationStatus::Declined->value)
                ->update(['token_expires_at' => $newDeadline]);

            if ($wasOpen) {
                $shouldNotify = true;
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
                action: 'RFQ_DEADLINE_EXTENDED',
                model: $locked,
                payloadBefore: ['submission_deadline' => $previousDeadline],
                payloadAfter: ['submission_deadline' => $newDeadline->toIso8601String()]
            );

            return $locked->fresh(['lines', 'attachments', 'invitations.supplier', 'buyer']) ?? $locked;
        });

        if ($shouldNotify && $notifications !== []) {
            $tenant = Tenant::query()->find($rfq->tenant_id);
            $buyerCompanyName = (string) ($tenant?->legal_name ?: $tenant?->name ?: config('app.name', 'FatturaFacile'));
            $frontendBase = rtrim((string) config('app.frontend_url', 'https://localhost:4200'), '/');
            $deadlineIso = $rfq->effectiveDeadline()->toIso8601String();

            DB::afterCommit(static function () use ($notifications, $rfq, $buyerCompanyName, $frontendBase, $deadlineIso): void {
                foreach ($notifications as $notify) {
                    Mail::to($notify['email'])
                        ->locale($notify['locale'])
                        ->queue(new RfqDeadlineExtendedMail(
                            rfqId: (string) $rfq->id,
                            rfqNumber: (string) $rfq->rfq_number,
                            rfqTitle: (string) $rfq->title,
                            buyerCompanyName: $buyerCompanyName,
                            supplierName: $notify['supplier_name'],
                            newDeadlineIso: $deadlineIso,
                            portalUrl: $frontendBase.'/supplier-portal',
                            mailLocale: $notify['locale'],
                        ));
                }
            });
        }

        return $rfq;
    }

    private function parseDeadline(string $value): CarbonImmutable
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return CarbonImmutable::parse($trimmed.' 23:59:59', 'UTC');
        }

        return CarbonImmutable::parse($trimmed)->utc();
    }
}
