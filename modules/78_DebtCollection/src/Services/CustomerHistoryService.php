<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Models\Event;
use Modules\DebtCollection\Models\Exclusion;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PaymentPlan;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * The dunning and recovery history shown in the customer master record: overdue balance, interest and fees charged, the
 * notices with their PEC receipts, enforcement dossiers, exclusions, payment plans and the chronological timeline.
 */
final class CustomerHistoryService
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $customerId, Carbon $today): array
    {
        $customer = Contact::query()->findOrFail($customerId);

        $notices = Notice::query()->with(['lines', 'receipts'])->where('customer_id', $customerId)->orderByDesc('issued_on')->orderByDesc('number')->get();
        $dossiers = Dossier::query()->where('customer_id', $customerId)->orderByDesc('generated_at')->get();
        $exclusions = Exclusion::query()->where('customer_id', $customerId)->orderByDesc('created_at')->get();
        $plans = PaymentPlan::query()->where('customer_id', $customerId)->orderByDesc('created_at')->get();
        $timeline = Event::query()->where('customer_id', $customerId)->orderByDesc('occurred_at')->limit(200)->get();

        $overdue = [];

        foreach (ReceivableItem::query()->where('customer_id', $customerId)->where('open_amount', '>', 0)->whereDate('due_date', '<', $today->toDateString())->whereNotIn('status', [ReceivableItem::StatusPaid, ReceivableItem::StatusWrittenOff])->get() as $item) {
            $overdue[$item->currency] = Decimal::add($overdue[$item->currency] ?? '0', (string) $item->open_amount);
        }

        $live = $notices->where('status', '!=', 'cancelled');
        $interest = $this->sumBy($live, 'interest');
        $fees = $this->sumBy($live, 'fee');

        return [
            'customer' => ['id' => $customer->id, 'name' => trim((string) $customer->company_name) !== '' ? $customer->company_name : trim($customer->first_name.' '.$customer->last_name), 'country' => $customer->country, 'pec' => $customer->pec, 'email' => $customer->email],
            'summary' => [
                'open_overdue' => $this->amounts($overdue),
                'current_level' => $live->max('level'),
                'interest_accrued' => $this->amounts($interest),
                'fees_charged' => $this->amounts($fees),
                'active_exclusions' => $exclusions->whereNull('released_at')->count(),
                'active_plans' => $plans->where('status', 'active')->count(),
            ],
            'notices' => $notices,
            'dossiers' => $dossiers,
            'exclusions' => $exclusions,
            'plans' => $plans,
            'timeline' => $timeline,
        ];
    }

    /**
     * @param  iterable<Notice>  $notices
     * @return array<string, string>
     */
    private function sumBy(iterable $notices, string $column): array
    {
        $sums = [];

        foreach ($notices as $notice) {
            $sums[$notice->currency] = Decimal::add($sums[$notice->currency] ?? '0', (string) $notice->{$column});
        }

        return $sums;
    }

    /**
     * @param  array<string, string>  $sums
     * @return list<array{currency: string, amount: string}>
     */
    private function amounts(array $sums): array
    {
        $rows = [];

        foreach ($sums as $currency => $amount) {
            $rows[] = ['currency' => (string) $currency, 'amount' => Decimal::round2($amount)];
        }

        return $rows;
    }
}
