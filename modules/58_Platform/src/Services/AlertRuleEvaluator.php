<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Platform\Models\NotificationAlertRule;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * Evaluates the active alert rules of a tenant. A rule notifies once per subject (a customer, or the tenant itself for
 * the company-wide metrics) when the metric crosses the threshold and again only after it has dropped to the threshold
 * or below and crossed it anew (the hit is rearmed as soon as the subject is no longer in breach).
 */
final class AlertRuleEvaluator
{
    /** Metrics measured once for the whole tenant: the subject of their hit is the tenant id. */
    private const array TenantWideMetrics = ['open_invoices_total', 'low_stock_items_count', 'sdi_rejected_count'];

    public function __construct(private readonly NotificationDispatcher $dispatcher, private readonly TenantNotifier $notifier) {}

    /**
     * @return int number of alerts raised
     */
    public function evaluate(string $tenantId): int
    {
        $raised = 0;

        NotificationAlertRule::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->each(function (NotificationAlertRule $rule) use ($tenantId, &$raised): void {
            $breaches = $this->breaches($rule, $tenantId);
            $known = \DB::table('notification_alert_rule_hits')->where('rule_id', $rule->id)->pluck('subject_id')->all();

            // Subjects back under the threshold are rearmed.
            \DB::table('notification_alert_rule_hits')->where('rule_id', $rule->id)->whereNotIn('subject_id', $breaches->keys()->map(fn ($k): string => (string) $k)->all())->delete();

            $recipients = $this->recipients($rule, $tenantId);

            foreach ($breaches as $subject => $amount) {
                if (in_array((string) $subject, $known, true)) {
                    continue;
                }

                \DB::table('notification_alert_rule_hits')->insert(['id' => (string) Str::uuid(), 'rule_id' => $rule->id, 'subject_id' => (string) $subject, 'fired_at' => now()]);

                [$body, $url] = $this->message($rule, (string) $subject, $amount);

                foreach ($recipients as $user) {
                    $this->dispatcher->send($user, $rule->category, $rule->name, $body, $url);
                }

                $raised++;
            }
        });

        return $raised;
    }

    /**
     * Subject id => measured value for the subjects over the threshold.
     *
     * @return Collection<string, string>
     */
    private function breaches(NotificationAlertRule $rule, string $tenantId): Collection
    {
        // Compared in PHP, not in HAVING: a bound parameter is text and SQLite orders text above any number.
        $over = static fn (mixed $value): bool => (float) $value > (float) $rule->threshold;

        return match ($rule->metric) {
            'customer_overdue_total' => Invoice::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('payment_status', 'overdue')
                ->where('status', '<>', 'paid')
                ->when($rule->currency !== null, fn ($q) => $q->where('currency', $rule->currency))
                ->selectRaw('contact_id, SUM(total_gross - COALESCE(total_paid, 0)) as due')
                ->groupBy('contact_id')
                ->pluck('due', 'contact_id')
                ->filter($over),
            'open_invoices_total' => $this->tenantWide($tenantId, $over, (string) Invoice::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', ['paid', 'draft', 'cancelled', 'void'])
                ->where(fn ($q) => $q->whereNull('invoice_type')->orWhere('invoice_type', '<>', 'proforma'))
                ->when($rule->currency !== null, fn ($q) => $q->where('currency', $rule->currency))
                ->selectRaw('COALESCE(SUM(total_gross - COALESCE(total_paid, 0)), 0) as due')
                ->value('due')),
            'low_stock_items_count' => $this->tenantWide($tenantId, $over, (string) Product::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('below_minimum_since')
                ->count()),
            'sdi_rejected_count' => $this->tenantWide($tenantId, $over, (string) FpaDocument::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', FpaStatus::Rejected->value)
                ->count()),
            default => collect(),
        };
    }

    /**
     * @param  callable(mixed): bool  $over
     * @return Collection<string, string>
     */
    private function tenantWide(string $tenantId, callable $over, string $value): Collection
    {
        return $over($value) ? collect([$tenantId => $value]) : collect();
    }

    /**
     * @return array{0: string, 1: string} body and deep link
     */
    private function message(NotificationAlertRule $rule, string $subject, mixed $value): array
    {
        $limit = $rule->threshold;

        if (in_array($rule->metric, self::TenantWideMetrics, true)) {
            $amount = number_format((float) $value, $rule->metric === 'open_invoices_total' ? 2 : 0, '.', '');

            return match ($rule->metric) {
                'open_invoices_total' => [__('Open invoices total :amount :currency (limit :limit)', ['amount' => $amount, 'currency' => $rule->currency, 'limit' => $limit]), '/invoicing/invoices'],
                'low_stock_items_count' => [__(':count items below minimum stock (limit :limit)', ['count' => $amount, 'limit' => $limit]), '/inventory/stock'],
                default => [__(':count invoices rejected by SDI (limit :limit)', ['count' => $amount, 'limit' => $limit]), '/sdi/documents'],
            };
        }

        $name = Contact::query()->withoutGlobalScopes()->whereKey($subject)->value('company_name') ?: $subject;

        return [
            __(':customer: overdue :amount :currency (limit :limit)', ['customer' => $name, 'amount' => number_format((float) $value, 2, '.', ''), 'currency' => $rule->currency, 'limit' => $limit]),
            '/crm/clients',
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(NotificationAlertRule $rule, string $tenantId): Collection
    {
        return $this->notifier->recipients($tenantId, $rule->recipient_user_ids);
    }
}
