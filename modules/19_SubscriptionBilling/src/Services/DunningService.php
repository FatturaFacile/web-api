<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Actions\Sales\SendPaymentReminder;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Events\SubscriptionCollectionFailedEvent;
use Modules\SubscriptionBilling\Models\DunningPolicy;
use Modules\SubscriptionBilling\Models\DunningRecord;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Throwable;

/**
 * Dunning of the subscription invoices.
 *
 * A failed collection (returned direct debit, declined card) opens a case and schedules retries every
 * `retry_interval_days`, up to `max_collection_retries`; each due retry is announced to Treasury with an event. The
 * daily run escalates the overdue invoices along the policy of the company: reminder, formal notice (both mailed by
 * the payment reminders of the invoicing), suspension of the subscription, then cancellation for non-payment. A paid
 * invoice closes its case and gives the subscription back its active status.
 */
final readonly class DunningService
{
    /** Defaults when the company has no dunning policy. */
    private const array DEFAULT_POLICY = ['soft_reminder_days' => 3, 'formal_notice_days' => 10, 'suspension_days' => 25, 'termination_days' => 45, 'max_collection_retries' => 3, 'retry_interval_days' => 3];

    private const array ACTIONS = [1 => 'payment_reminder', 2 => 'formal_notice', 3 => 'subscription_suspended', 4 => 'subscription_cancelled'];

    public function __construct(
        private SubscriptionHistory $history,
        private RenewalService $renewals,
        private SendPaymentReminder $reminders,
    ) {}

    /**
     * Records a failed collection of an issued, unpaid invoice of the subscription and schedules the next retry.
     *
     * @throws ValidationException when the invoice is not an unpaid issued invoice of the subscription
     */
    public function recordCollectionFailure(string $subscriptionId, string $invoiceId, ?string $reason, ?string $actorUserId): DunningRecord
    {
        return DB::transaction(function () use ($subscriptionId, $invoiceId, $reason, $actorUserId): DunningRecord {
            $subscription = Subscription::query()->with(['plan', 'items'])->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();
            $invoice = $this->subscriptionInvoice($subscription, $invoiceId);

            if ($invoice === null || $invoice->status !== 'posted' || $invoice->payment_status === 'paid') {
                throw ValidationException::withMessages(['invoice_id' => __('The invoice is not an unpaid issued invoice of this subscription.')]);
            }

            $policy = $this->policy((string) $subscription->tenant_id);
            $record = $this->openRecord($subscription, $invoice, $policy);
            $retryCount = (int) $record->retry_count + 1;
            $retriesLeft = $retryCount <= (int) $policy['max_collection_retries'];
            $nextRetry = $retriesLeft ? now()->addDays((int) $policy['retry_interval_days']) : null;

            $record->forceFill([
                'retry_count' => $retryCount,
                'next_retry_at' => $nextRetry,
                'last_failure_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'last_action_at' => now(),
                'communications_log' => [...($record->communications_log ?? []), ['at' => now()->toIso8601String(), 'action' => 'collection_failed', 'reason' => $reason, 'actor' => $actorUserId]],
            ])->save();

            if (! $retriesLeft && (int) $record->dunning_level < 2) {
                $this->escalate($subscription, $record, $invoice, 2);
            }

            $this->markPastDue($subscription, $actorUserId);

            $this->history->publish(new SubscriptionCollectionFailedEvent(
                subscriptionId: (string) $subscription->id,
                invoiceId: (string) $invoice->id,
                dunningRecordId: (string) $record->id,
                retryCount: $retryCount,
                nextRetryAt: $nextRetry?->toIso8601String(),
                reason: $reason,
                retryDue: false,
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->history->companyOf($subscription)
            ));

            return $record->refresh();
        });
    }

    /**
     * Daily dunning of a tenant: due retries, escalation of the overdue invoices, closing of the paid cases.
     *
     * @return array{retries_announced: int, escalations: int, resolved: int, errors: list<array{invoice_id: string, error: string}>}
     */
    public function processDunning(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $result = ['retries_announced' => 0, 'escalations' => 0, 'resolved' => 0, 'errors' => []];
        $policy = $this->policy($tenantId);

        $result['resolved'] = $this->resolvePaid($tenantId);
        $result['retries_announced'] = $this->announceDueRetries($tenantId);

        $overdue = InvoiceSchedule::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('billing_kind', ['recurring', 'proration'])
            ->where('status', 'invoiced')
            ->whereNotNull('invoice_id')
            ->whereHas('invoice', fn ($query) => $query->where('status', 'posted')->where('payment_status', '!=', 'paid')->whereDate('due_date', '<', $today->toDateString()))
            ->get(['id', 'subscription_id', 'invoice_id']);

        foreach ($overdue as $schedule) {
            try {
                $escalated = DB::transaction(function () use ($schedule, $policy, $today): bool {
                    $subscription = Subscription::query()->with(['plan', 'items'])->whereKey($schedule->subscription_id)->lockForUpdate()->first();
                    $invoice = SalesInvoice::withoutGlobalScopes()->whereKey($schedule->invoice_id)->first();

                    if ($subscription === null || $invoice === null || $invoice->payment_status === 'paid') {
                        return false;
                    }

                    $daysOverdue = (int) CarbonImmutable::parse($invoice->due_date)->startOfDay()->diffInDays($today);
                    $target = $this->targetLevel($daysOverdue, $policy);

                    if ($target === 0) {
                        return false;
                    }

                    $record = $this->openRecord($subscription, $invoice, $policy);
                    $record->forceFill(['days_overdue' => $daysOverdue])->save();

                    if ($target <= (int) $record->dunning_level && $record->action_taken !== 'opened') {
                        return false;
                    }

                    $this->escalate($subscription, $record, $invoice, $target);

                    return true;
                });
            } catch (Throwable $e) {
                $result['errors'][] = ['invoice_id' => (string) $schedule->invoice_id, 'error' => mb_substr($e->getMessage(), 0, 500)];

                continue;
            }

            if ($escalated) {
                $result['escalations']++;
            }
        }

        return $result;
    }

    /**
     * The dunning policy of the company (its default one), or the built-in defaults.
     *
     * @return array{id: ?string, soft_reminder_days: int, formal_notice_days: int, suspension_days: int, termination_days: int, max_collection_retries: int, retry_interval_days: int}
     */
    public function policy(string $tenantId): array
    {
        $policy = DunningPolicy::query()->where('tenant_id', $tenantId)->orderByDesc('is_default')->orderBy('created_at')->first();

        if ($policy === null) {
            return ['id' => null, ...self::DEFAULT_POLICY];
        }

        return [
            'id' => (string) $policy->id,
            'soft_reminder_days' => (int) $policy->soft_reminder_days,
            'formal_notice_days' => (int) $policy->formal_notice_days,
            'suspension_days' => (int) $policy->suspension_days,
            'termination_days' => (int) $policy->termination_days,
            'max_collection_retries' => (int) ($policy->max_collection_retries ?? self::DEFAULT_POLICY['max_collection_retries']),
            'retry_interval_days' => (int) ($policy->retry_interval_days ?? self::DEFAULT_POLICY['retry_interval_days']),
        ];
    }

    /**
     * @param  array<string, int|string|null>  $policy
     */
    private function targetLevel(int $daysOverdue, array $policy): int
    {
        return match (true) {
            $daysOverdue >= (int) $policy['termination_days'] => 4,
            $daysOverdue >= (int) $policy['suspension_days'] => 3,
            $daysOverdue >= (int) $policy['formal_notice_days'] => 2,
            $daysOverdue >= (int) $policy['soft_reminder_days'] => 1,
            default => 0,
        };
    }

    /**
     * Raises a case to a level: a mailed reminder at every level, then the suspension (3) or the cancellation (4)
     * of a subscription that is still live.
     */
    private function escalate(Subscription $subscription, DunningRecord $record, SalesInvoice $invoice, int $level): void
    {
        $log = $record->communications_log ?? [];
        $entry = ['at' => now()->toIso8601String(), 'action' => self::ACTIONS[$level], 'level' => $level];

        try {
            $legacyInvoice = Invoice::withoutGlobalScopes()->with(['contact' => fn ($query) => $query->withoutGlobalScopes()])->findOrFail($invoice->id);
            $reminder = $this->reminders->execute($legacyInvoice, force: true);
            $entry['payment_reminder_id'] = $reminder?->id;
        } catch (ValidationException $e) {
            $entry['reminder_error'] = collect($e->errors())->flatten()->first();
        }

        $live = ! $subscription->isCancelled();

        if ($level === 3 && $live && in_array($subscription->status, ['active', 'past_due', 'trialing'], true)) {
            $previous = $subscription->status;
            $subscription->forceFill(['status' => 'suspended'])->save();
            [$before, $after] = $this->history->refreshMrr($subscription, false);
            $this->history->version($subscription, 'dunning_suspension', $before, $after, CarbonImmutable::today(), "Invoice {$invoice->invoice_number} unpaid", null);
            $this->history->audit('SUBSCRIPTION_SUSPENDED_FOR_NON_PAYMENT', $subscription, ['status' => $previous], ['status' => 'suspended', 'invoice_id' => (string) $invoice->id]);
        }

        if ($level === 4 && $live) {
            $subscription->forceFill([
                'cancellation_reason' => mb_substr("Non-payment of invoice {$invoice->invoice_number}", 0, 150),
                'cancellation_reason_code' => 'non_payment',
                'cancellation_type' => 'immediate',
                'cancel_at_period_end' => false,
            ])->save();
            $this->renewals->close($subscription, 'cancelled', CarbonImmutable::today(), "Non-payment of invoice {$invoice->invoice_number}", null);
        }

        $record->forceFill([
            'dunning_level' => $level,
            'action_taken' => self::ACTIONS[$level],
            'status' => 'active',
            'last_action_at' => now(),
            'communications_log' => [...$log, $entry],
        ])->save();
    }

    /**
     * The open case of an invoice, opened when missing (the subscription row is locked by the caller).
     *
     * @param  array<string, int|string|null>  $policy
     */
    private function openRecord(Subscription $subscription, SalesInvoice $invoice, array $policy): DunningRecord
    {
        $record = DunningRecord::query()->where('invoice_id', $invoice->id)->where('status', 'active')->lockForUpdate()->first();

        return $record ?? DunningRecord::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'dunning_policy_id' => $policy['id'],
            'dunning_level' => 0,
            'status' => 'active',
            'action_taken' => 'opened',
            'retry_count' => 0,
            'communications_log' => [],
        ]);
    }

    private function markPastDue(Subscription $subscription, ?string $actorUserId): void
    {
        if (! in_array($subscription->status, ['active', 'trialing'], true)) {
            return;
        }

        $previous = $subscription->status;
        $subscription->forceFill(['status' => 'past_due'])->save();
        $this->history->audit('SUBSCRIPTION_PAST_DUE', $subscription, ['status' => $previous], ['status' => 'past_due', 'actor' => $actorUserId]);
    }

    /**
     * Closes the cases of the paid invoices; a subscription without other open cases is active again.
     */
    private function resolvePaid(string $tenantId): int
    {
        $records = DunningRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('invoice_id', SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('payment_status', 'paid')->select('id'))
            ->get();

        foreach ($records as $record) {
            DB::transaction(function () use ($record): void {
                $subscription = Subscription::query()->with(['plan', 'items'])->whereKey($record->subscription_id)->lockForUpdate()->first();
                $record->forceFill([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'next_retry_at' => null,
                    'communications_log' => [...($record->communications_log ?? []), ['at' => now()->toIso8601String(), 'action' => 'paid']],
                ])->save();

                if ($subscription === null || ! in_array($subscription->status, ['past_due', 'suspended'], true)) {
                    return;
                }

                if (DunningRecord::query()->where('subscription_id', $subscription->id)->where('status', 'active')->exists()) {
                    return;
                }

                $previous = $subscription->status;
                $subscription->forceFill(['status' => 'active'])->save();
                [$before, $after] = $this->history->refreshMrr($subscription);
                $this->history->version($subscription, 'dunning_resolved', $before, $after, CarbonImmutable::today(), 'Overdue invoices paid', null);
                $this->history->audit('SUBSCRIPTION_DUNNING_RESOLVED', $subscription, ['status' => $previous], ['status' => 'active']);
            });
        }

        return $records->count();
    }

    /**
     * Announces the collection retries that are due and clears their date until the next result arrives.
     */
    private function announceDueRetries(string $tenantId): int
    {
        $due = DunningRecord::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())->get();

        foreach ($due as $record) {
            DB::transaction(function () use ($record): void {
                $locked = DunningRecord::query()->whereKey($record->id)->lockForUpdate()->first();

                if ($locked === null || $locked->next_retry_at === null) {
                    return;
                }

                $retryAt = $locked->next_retry_at->toIso8601String();
                $locked->forceFill([
                    'next_retry_at' => null,
                    'last_action_at' => now(),
                    'communications_log' => [...($locked->communications_log ?? []), ['at' => now()->toIso8601String(), 'action' => 'collection_retry_due']],
                ])->save();

                $this->history->publish(new SubscriptionCollectionFailedEvent(
                    subscriptionId: (string) $locked->subscription_id,
                    invoiceId: (string) $locked->invoice_id,
                    dunningRecordId: (string) $locked->id,
                    retryCount: (int) $locked->retry_count,
                    nextRetryAt: $retryAt,
                    reason: $locked->last_failure_reason,
                    retryDue: true,
                    tenantId: (string) $locked->tenant_id,
                    companyId: (string) $locked->tenant_id
                ));
            });
        }

        return $due->count();
    }

    private function subscriptionInvoice(Subscription $subscription, string $invoiceId): ?SalesInvoice
    {
        $belongs = InvoiceSchedule::query()->where('subscription_id', $subscription->id)->where('invoice_id', $invoiceId)->whereIn('billing_kind', ['recurring', 'proration'])->exists();

        return $belongs ? SalesInvoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->whereKey($invoiceId)->first() : null;
    }
}
