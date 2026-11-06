<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Enums\ReceiptKind;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Support\Money;

/**
 * Records a payment of an instalment: receipt, accounting and state of the instalment in one transaction.
 */
final class FeePaymentService
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly FeeLedgerBridge $ledger,
    ) {}

    /**
     * @param  array{amount: string, paid_on: string, method: PaymentMethod|string, reference?: ?string, receipt_kind?: ?string}  $data
     */
    public function record(FeeInstalment $instalment, array $data, ?string $actorId = null): FeePayment
    {
        return DB::transaction(function () use ($instalment, $data, $actorId): FeePayment {
            /** @var FeeInstalment $locked */
            $locked = FeeInstalment::query()->whereKey($instalment->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [InstalmentStatus::Paid, InstalmentStatus::Cancelled], true)) {
                throw AssociationException::conflict(__('associations.instalment_closed'));
            }

            $amount = Money::normalize($data['amount']);

            if (! Money::isPositive($amount) || Money::compare($amount, $locked->outstanding()) > 0) {
                throw new AssociationException(__('associations.payment_exceeds_outstanding'));
            }

            $settings = AssociationSetting::current();
            $assignment = $locked->assignment()->with(['plan', 'member.contact', 'payer', 'course.discipline'])->firstOrFail();
            $paidOn = CarbonImmutable::parse($data['paid_on']);
            $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']);
            $kind = ReceiptKind::tryFrom((string) ($data['receipt_kind'] ?? $settings->default_receipt_kind)) ?? ReceiptKind::Simple;

            $receipt = $this->receipts->issue($assignment, $amount, $kind, $paidOn);
            $accounting = $settings->ledger_enabled
                ? $this->ledger->post($assignment, $receipt, $amount, $paidOn, $method, $data['reference'] ?? null, $actorId)
                : ['invoice_id' => null, 'payment_id' => null];

            $payment = FeePayment::create([
                ...$accounting,
                'fee_instalment_id' => $locked->id,
                'member_id' => $assignment->member_id,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'receipt_id' => $receipt->id,
                'recorded_by' => $actorId,
            ]);

            $paid = Money::add((string) $locked->paid_amount, $amount);
            $locked->update([
                'paid_amount' => $paid,
                'status' => Money::compare($paid, (string) $locked->amount) >= 0 ? InstalmentStatus::Paid : InstalmentStatus::Partial,
            ]);

            return $payment->load('receipt');
        });
    }
}
