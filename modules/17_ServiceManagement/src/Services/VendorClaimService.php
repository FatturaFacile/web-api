<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\VendorClaimStatus;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

final readonly class VendorClaimService
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @param  array{supplier_rma_reference?: string|null, notes?: string|null, claimed_amount?: int|float|string|null}  $attributes
     */
    public function update(VendorWarrantyClaim $claim, array $attributes): VendorWarrantyClaim
    {
        $changes = array_intersect_key($attributes, array_flip(['supplier_rma_reference', 'notes', 'claimed_amount']));

        if (array_key_exists('claimed_amount', $changes) && $changes['claimed_amount'] !== null) {
            $changes['claimed_amount'] = number_format((float) $changes['claimed_amount'], 2, '.', '');
        }

        $claim->fill($changes);
        $claim->save();

        return $claim->fresh(['rmaOrder', 'supplier']) ?? $claim;
    }

    public function transition(
        VendorWarrantyClaim $claim,
        VendorClaimStatus $target,
        ?string $creditNoteReference = null,
        ?string $note = null,
    ): VendorWarrantyClaim {
        return DB::transaction(function () use ($claim, $target, $creditNoteReference, $note): VendorWarrantyClaim {
            /** @var VendorWarrantyClaim $claim */
            $claim = VendorWarrantyClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
            $from = VendorClaimStatus::from($claim->status);

            if (! $from->canTransitionTo($target)) {
                throw WarrantyRmaException::invalidTransition($from->value, $target->value);
            }

            $claim->status = $target->value;

            if ($creditNoteReference !== null) {
                $claim->credit_note_reference = $creditNoteReference;
            }

            if ($target->isResolution()) {
                $claim->resolved_at = now();
            }

            if ($note !== null && $note !== '') {
                $claim->notes = trim(($claim->notes ? $claim->notes."\n" : '').'['.$target->value.'] '.$note);
            }

            $claim->save();

            $this->auditLedger->record(
                'VENDOR_CLAIM_TRANSITIONED',
                $claim,
                ['status' => $from->value],
                ['status' => $target->value, 'credit_note_reference' => $claim->credit_note_reference],
                'MODULE_17_ESM'
            );

            return $claim->fresh(['rmaOrder', 'supplier']) ?? $claim;
        });
    }
}
