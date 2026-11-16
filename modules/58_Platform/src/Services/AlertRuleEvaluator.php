<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Platform\Models\NotificationAlertRule;

/**
 * Evaluates the active alert rules of a tenant. A rule notifies once per subject (for example a customer) when the
 * metric crosses the threshold and again only after it has dropped below it and crossed it anew.
 */
final class AlertRuleEvaluator
{
    private const array DefaultRecipientRoles = ['owner', 'admin'];

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

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
                $name = Contact::query()->withoutGlobalScopes()->whereKey($subject)->value('company_name') ?: (string) $subject;

                foreach ($recipients as $user) {
                    $this->dispatcher->send(
                        $user,
                        $rule->category,
                        $rule->name,
                        __(':customer: overdue :amount :currency (limit :limit)', ['customer' => $name, 'amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $rule->currency, 'limit' => $rule->threshold]),
                        '/crm/clients',
                    );
                }

                $raised++;
            }
        });

        return $raised;
    }

    /**
     * Subject id => measured amount for the subjects over the threshold.
     *
     * @return Collection<string, string>
     */
    private function breaches(NotificationAlertRule $rule, string $tenantId): Collection
    {
        return match ($rule->metric) {
            'customer_overdue_total' => Invoice::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('payment_status', 'overdue')
                ->where('status', '<>', 'paid')
                ->when($rule->currency !== null, fn ($q) => $q->where('currency', $rule->currency))
                ->selectRaw('contact_id, SUM(total_gross - COALESCE(total_paid, 0)) as due')
                ->groupBy('contact_id')
                ->pluck('due', 'contact_id')
                // Compared here, not in HAVING: a bound parameter is text and SQLite orders text above any number.
                ->filter(static fn (mixed $due): bool => (float) $due > (float) $rule->threshold),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(NotificationAlertRule $rule, string $tenantId): Collection
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return collect();
        }

        // Only members of the tenant can be addressed, whatever the stored ids say.
        if ($rule->recipient_user_ids !== null && $rule->recipient_user_ids !== []) {
            return $tenant->users()->whereIn('users.id', $rule->recipient_user_ids)->get();
        }

        return $tenant->users()->wherePivotIn('role', self::DefaultRecipientRoles)->get();
    }
}
