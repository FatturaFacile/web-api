<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\ReimbursementAttachment;
use Modules\Associations\Support\Money;

/**
 * Expense and mileage reimbursements: submitted with their proofs, approved or rejected, then paid and posted to the
 * ledger on the expense account of the request, of the campaign or of the settings, in that order. The amount of a
 * journey is distance times the unit price the association applies: the price is an input, never a rate of the code.
 */
final class ReimbursementService
{
    public function __construct(
        private readonly PrivateFiles $files,
        private readonly AssociationLedgerPoster $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): Reimbursement
    {
        if ($data['kind'] === Reimbursement::Mileage) {
            $data['amount'] = $this->mileageAmount((string) $data['distance_km'], (string) $data['unit_price']);
        } else {
            $data['amount'] = Money::normalize((string) $data['amount']);
            $data['distance_km'] = null;
            $data['unit_price'] = null;
            $data['vehicle'] = null;
            $data['plate'] = null;
        }

        if (! Money::isPositive($data['amount'])) {
            throw new AssociationException(__('association_governance.amount_not_positive'));
        }

        if (($data['campaign_id'] ?? null) !== null && ($data['cost_center_id'] ?? null) === null) {
            $data['cost_center_id'] = Campaign::query()->find($data['campaign_id'])?->cost_center_id;
        }

        return Reimbursement::create([...$data, 'status' => Reimbursement::Submitted]);
    }

    public function attach(Reimbursement $reimbursement, UploadedFile $file): ReimbursementAttachment
    {
        if ($reimbursement->status === Reimbursement::Paid) {
            throw AssociationException::conflict(__('association_governance.reimbursement_locked'));
        }

        return ReimbursementAttachment::create([...$this->files->store($file, (string) $reimbursement->tenant_id, "reimbursements/{$reimbursement->id}"), 'reimbursement_id' => $reimbursement->id]);
    }

    public function decide(Reimbursement $reimbursement, bool $approved, ?string $reason, ?string $userId): Reimbursement
    {
        if ($reimbursement->status !== Reimbursement::Submitted) {
            throw AssociationException::conflict(__('association_governance.reimbursement_locked'));
        }

        $reimbursement->update([
            'status' => $approved ? Reimbursement::Approved : Reimbursement::Rejected,
            'rejection_reason' => $approved ? null : $reason,
            'decided_by' => $userId,
            'decided_at' => now(),
        ]);

        return $reimbursement;
    }

    public function pay(Reimbursement $reimbursement, CarbonImmutable $paidOn, string $method, ?string $userId): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $paidOn, $method, $userId): Reimbursement {
            /** @var Reimbursement $locked */
            $locked = Reimbursement::query()->whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Reimbursement::Approved) {
                throw AssociationException::conflict(__('association_governance.reimbursement_not_approved'));
            }

            $account = $locked->expense_account_id ?? $locked->campaign?->expense_account_id ?? AssociationSetting::current()->reimbursement_account_id;

            if ($account === null) {
                throw new AssociationException(__('association_governance.reimbursement_account_missing'));
            }

            $entry = $this->ledger->postOutflow(
                (string) $account,
                Money::normalize((string) $locked->amount),
                $paidOn,
                $method,
                (string) __('association_governance.reimbursement_posting', ['description' => $locked->description]),
                'association_reimbursement',
                $locked->id,
                ['cost_center_id' => $locked->cost_center_id, 'project_id' => $locked->project_id],
                $userId,
            );

            $locked->update(['status' => Reimbursement::Paid, 'paid_on' => $paidOn->toDateString(), 'payment_method' => $method, 'journal_entry_id' => $entry->id]);

            return $locked;
        });
    }

    public function mileageAmount(string $distance, string $unitPrice): string
    {
        $raw = bcmul(Money::normalize($distance), bcadd($unitPrice, '0', 4), 6);

        return bcadd($raw, '0.005', 2);
    }
}
