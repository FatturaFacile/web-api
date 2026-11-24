<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Modules\GeneralLedger\Models\Us1099Payment;
use Modules\GeneralLedger\Models\Us1099Recipient;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Yearly 1099 data of a tenant: payments summed by recipient, form and box, with the reporting threshold and the data
 * the filing still lacks (tax identification number, W-9). The amounts are as captured; filing itself is out of scope.
 */
final class Us1099Service
{
    /** Royalties (1099-MISC box 2) are reportable from this amount; the other boxes from the general threshold. */
    private const string RoyaltyThreshold = '10.00';

    /**
     * @return array{year: int, threshold: string, recipients: list<array<string, mixed>>}
     */
    public function summary(string $tenantId, int $year): array
    {
        $payments = Us1099Payment::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('paid_on', ["{$year}-01-01", "{$year}-12-31"])
            ->get()
            ->groupBy('recipient_id');

        $recipients = Us1099Recipient::query()->where('tenant_id', $tenantId)->whereIn('id', $payments->keys())->orderBy('name')->get();
        $rows = [];

        foreach ($recipients as $recipient) {
            $boxes = [];

            foreach ($payments[$recipient->id] as $payment) {
                $key = "{$payment->form_type}|{$payment->box}";
                $boxes[$key] = Decimal::add($boxes[$key] ?? '0', (string) $payment->amount);
            }

            ksort($boxes);
            $lines = [];
            $reportable = false;

            foreach ($boxes as $key => $amount) {
                [$form, $box] = explode('|', $key);
                $threshold = $form === '1099-MISC' && $box === '2' ? self::RoyaltyThreshold : Us1099Payment::Threshold;
                $meets = Decimal::compare($amount, $threshold) >= 0;
                $reportable = $reportable || ($meets && ! $recipient->is_exempt);
                $lines[] = ['form_type' => $form, 'box' => $box, 'amount' => Decimal::round2($amount), 'meets_threshold' => $meets];
            }

            $rows[] = [
                'recipient_id' => $recipient->id,
                'name' => $recipient->name,
                'tin_type' => $recipient->tin_type,
                'tin_last4' => $recipient->tin_last4,
                'is_exempt' => $recipient->is_exempt,
                'reportable' => $reportable,
                'missing_tin' => $recipient->tin_last4 === null,
                'missing_w9' => $recipient->w9_received_on === null,
                'boxes' => $lines,
            ];
        }

        return ['year' => $year, 'threshold' => Us1099Payment::Threshold, 'recipients' => $rows];
    }
}
