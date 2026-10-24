<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Actions\Sales\ConvertToInvoice;
use App\Models\Subscription;
use App\Models\SubscriptionRun;
use App\Services\Sales\DocumentEventRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunSubscriptionBilling
{
    /**
     * Maximum number of missed periods billed in a single run (catch-up guard).
     */
    private const MAX_PERIODS_PER_RUN = 12;

    public function __construct(
        private readonly ConvertToInvoice $toInvoice,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Bill every period due up to `$asOf`. Idempotent per (subscription, period start); on failure
     * nothing is advanced and a failed run is logged so the next execution retries.
     *
     * @return array<int, SubscriptionRun>
     */
    public function execute(Subscription $subscription, ?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::instance($asOf ?? now())->startOfDay();

        try {
            return DB::transaction(fn (): array => $this->billDuePeriods($subscription->id, $asOf));
        } catch (Throwable $e) {
            $start = $subscription->next_billing_date;

            if ($start !== null) {
                SubscriptionRun::updateOrCreate(
                    ['subscription_id' => $subscription->id, 'period_start' => $start->toDateString()],
                    [
                        'tenant_id' => $subscription->tenant_id,
                        'period_end' => $subscription->interval->nextFromAnchor($subscription->start_date, $start, $subscription->interval_count)->subDay()->toDateString(),
                        'status' => 'failed',
                        'error' => mb_substr($e->getMessage(), 0, 1000),
                    ]
                );
            }

            return [];
        }
    }

    /**
     * @return array<int, SubscriptionRun>
     */
    private function billDuePeriods(string $subscriptionId, CarbonImmutable $asOf): array
    {
        $subscription = Subscription::with(['items', 'contact'])->lockForUpdate()->findOrFail($subscriptionId);
        $runs = [];

        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN; $i++) {
            $periodStart = $subscription->next_billing_date;

            if ($subscription->status !== 'active' || $periodStart === null || $periodStart->gt($asOf)) {
                break;
            }

            $nextDate = $subscription->interval->nextFromAnchor($subscription->start_date, $periodStart, $subscription->interval_count);

            $existing = SubscriptionRun::where('subscription_id', $subscription->id)
                ->where('period_start', $periodStart->toDateString())->first();

            if ($existing === null || $existing->status !== 'success') {
                $invoice = $this->toInvoice->fromSubscription($subscription, $periodStart);

                $run = SubscriptionRun::updateOrCreate(
                    ['subscription_id' => $subscription->id, 'period_start' => $periodStart->toDateString()],
                    [
                        'tenant_id' => $subscription->tenant_id,
                        'invoice_id' => $invoice->id,
                        'period_end' => $nextDate->subDay()->toDateString(),
                        'status' => 'success',
                        'error' => null,
                    ]
                );
                $runs[] = $run;
                $this->events->record($subscription, 'billed', ['invoice_id' => $invoice->id, 'period_start' => $periodStart->toDateString()]);
            }

            $subscription->next_billing_date = $nextDate;
            $subscription->last_billed_at = now();

            if ($subscription->end_date !== null && $nextDate->gt($subscription->end_date)) {
                if ($subscription->auto_renew) {
                    $subscription->end_date = $subscription->interval->advance($subscription->end_date, $subscription->interval_count);
                    $subscription->renewal_notified_at = null;
                    $this->events->record($subscription, 'renewed', ['end_date' => $subscription->end_date->toDateString()]);
                } else {
                    $subscription->status = 'expired';
                    $subscription->next_billing_date = null;
                    $this->events->record($subscription, 'expired');
                }
            }

            $subscription->save();
        }

        return $runs;
    }
}
