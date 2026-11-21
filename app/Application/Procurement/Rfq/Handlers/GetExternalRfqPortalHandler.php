<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Queries\GetExternalRfqPortalQuery;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Builds the external supplier portal read model, recording first_viewed_at and stripping
 * internal buyer fields (target_unit_price, internal notes, evaluation weights, and competitor data).
 */
final readonly class GetExternalRfqPortalHandler
{
    public function __construct(
        private RfqSupplierRecipientResolver $recipientResolver,
    ) {}

    /**
     * @return array{rfq: RequestForQuotation, invitation: RfqSupplierInvitation, buyer_company: array<string, mixed>, bid: ?SupplierBid, preferred_locale: string, is_open: bool, can_submit: bool}
     */
    public function handle(GetExternalRfqPortalQuery $query): array
    {
        /** @var RfqSupplierInvitation $invitation */
        $invitation = RfqSupplierInvitation::query()
            ->with('supplier')
            ->whereKey($query->invitationId)
            ->firstOrFail();

        /** @var RequestForQuotation $rfq */
        $rfq = RequestForQuotation::query()
            ->with([
                'lines',
                'attachments' => static fn ($q) => $q->where('is_visible_to_suppliers', true),
                'buyer',
            ])
            ->whereKey($invitation->rfq_id)
            ->firstOrFail();

        if ($invitation->first_viewed_at === null) {
            $now = CarbonImmutable::now('UTC');
            $updates = ['first_viewed_at' => $now];

            if (in_array($invitation->statusEnum(), [InvitationStatus::Pending, InvitationStatus::Sent, InvitationStatus::Invited], true)) {
                $updates['status'] = InvitationStatus::Viewed->value;
            }

            $invitation->update($updates);
            $invitation->refresh();
        }

        $bid = SupplierBid::query()
            ->with('lines')
            ->where('rfq_id', $rfq->id)
            ->where('supplier_id', $invitation->supplier_id)
            ->first();

        $tenant = Tenant::query()->find($rfq->tenant_id);
        $recipient = $invitation->supplier !== null
            ? $this->recipientResolver->resolve($invitation->supplier, $tenant)
            : ['locale' => 'en'];

        $locale = $query->requestedLocale ?? $recipient['locale'];
        $now = CarbonImmutable::now('UTC');
        $isOpen = $rfq->statusEnum()->isOpenForBidding() && $now->lte($rfq->effectiveDeadline());
        $canSubmit = $isOpen && $invitation->statusEnum()->canSubmitBid();

        return [
            'rfq' => $rfq,
            'invitation' => $invitation,
            'buyer_company' => [
                'name' => (string) ($tenant?->legal_name ?: $tenant?->name ?: config('app.name', 'FatturaFacile')),
                'country' => (string) ($tenant->country ?? 'IT'),
                'vat_number' => $tenant?->vat_number,
            ],
            'bid' => $bid,
            'preferred_locale' => $locale,
            'is_open' => $isOpen,
            'can_submit' => $canSubmit,
        ];
    }
}
