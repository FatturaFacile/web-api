<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;
use Modules\Workshop\Models\WorkshopWarrantyClaim;

/**
 * Warranty claims of a workshop against the supplier of a part or service fitted on a work folder: draft, submitted to
 * the supplier, accepted or rejected, and finally reimbursed with the amount the supplier pays back.
 */
final class WarrantyClaimService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /**
     * Opens a draft claim on a folder. When it names a folder line, the line gives the product and, unless the request
     * says otherwise, the claimed amount (its net total).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($data): WorkshopWarrantyClaim {
            $folder = WorkshopFolder::query()->findOrFail($data['folder_id']);
            $line = null;

            if (isset($data['folder_line_id'])) {
                $line = WorkshopFolderLine::query()->findOrFail($data['folder_line_id']);

                if ($line->folder_id !== $folder->id) {
                    throw new BusinessRuleException('The line belongs to another folder.');
                }
            }

            $claim = new WorkshopWarrantyClaim;
            $claim->fill([
                ...$data,
                'product_id' => $data['product_id'] ?? $line?->product_id,
                'claimed_amount' => (string) ($data['claimed_amount'] ?? $line->line_total ?? '0'),
                'claim_number' => $this->numbering->next('workshop_warranty_claim')['number'],
                'currency' => $folder->currency,
                'status' => 'draft',
            ]);
            $claim->save();

            return $claim;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkshopWarrantyClaim $claim, array $data): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $data): WorkshopWarrantyClaim {
            $locked = $this->lock($claim);

            if ($locked->status !== 'draft') {
                throw new BusinessRuleException("A {$locked->status} claim cannot change.");
            }

            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Sends the claim to the supplier: it needs a supplier and an amount.
     */
    public function submit(WorkshopWarrantyClaim $claim, ?string $supplierReference): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $supplierReference): WorkshopWarrantyClaim {
            $locked = $this->lock($claim);
            $this->assertTransition($locked, 'submitted');

            if ($locked->supplier_contact_id === null) {
                throw new BusinessRuleException('A claim needs a supplier before it is submitted.');
            }

            if (Decimal::compare((string) $locked->claimed_amount, '0') <= 0) {
                throw new BusinessRuleException('A claim needs an amount before it is submitted.');
            }

            $locked->update(['status' => 'submitted', 'submitted_at' => now(), 'supplier_reference' => $supplierReference ?? $locked->supplier_reference]);

            return $locked;
        });
    }

    public function accept(WorkshopWarrantyClaim $claim, ?string $note): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $note): WorkshopWarrantyClaim {
            $locked = $this->lock($claim);
            $this->assertTransition($locked, 'accepted');
            $locked->update(['status' => 'accepted', 'decided_at' => now(), 'decision_note' => $note]);

            return $locked;
        });
    }

    public function reject(WorkshopWarrantyClaim $claim, ?string $reason): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $reason): WorkshopWarrantyClaim {
            $locked = $this->lock($claim);
            $this->assertTransition($locked, 'rejected');
            $locked->update(['status' => 'rejected', 'decided_at' => now(), 'decision_note' => $reason]);

            return $locked;
        });
    }

    /**
     * Records the supplier reimbursement of an accepted claim; it cannot exceed the claimed amount.
     */
    public function reimburse(WorkshopWarrantyClaim $claim, string $amount, string $date): WorkshopWarrantyClaim
    {
        return DB::transaction(function () use ($claim, $amount, $date): WorkshopWarrantyClaim {
            $locked = $this->lock($claim);
            $this->assertTransition($locked, 'reimbursed');

            if (Decimal::compare($amount, (string) $locked->claimed_amount) > 0) {
                throw new BusinessRuleException('The reimbursement cannot exceed the claimed amount.');
            }

            $locked->update(['status' => 'reimbursed', 'reimbursed_amount' => Decimal::round2($amount), 'reimbursed_on' => $date]);

            return $locked;
        });
    }

    private function lock(WorkshopWarrantyClaim $claim): WorkshopWarrantyClaim
    {
        return WorkshopWarrantyClaim::query()->lockForUpdate()->findOrFail($claim->id);
    }

    private function assertTransition(WorkshopWarrantyClaim $claim, string $target): void
    {
        if (! in_array($target, WorkshopWarrantyClaim::Transitions[$claim->status] ?? [], true)) {
            throw new BusinessRuleException("A claim cannot move from {$claim->status} to {$target}.");
        }
    }
}
