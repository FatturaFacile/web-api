<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\CRM\Models\Account;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Services\SurveyScheduler;
use Modules\CustomerSatisfaction\Services\SurveyTriggerContext;

/**
 * Schedules the relationship survey (NPS) of the active clients every six months: one per client, never twice inside 180 days.
 * The fatigue cooldown still applies, so a client surveyed after a ticket is skipped and recorded as throttled.
 */
final class EnqueueSemiAnnualSurveysCommand extends Command
{
    protected $signature = 'surveys:enqueue-semi-annual {--tenant=* : Limit to these tenants}';

    protected $description = 'Schedules the semi-annual relationship survey of the active clients';

    public function handle(TenantContext $tenantContext, SurveyScheduler $scheduler): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $scheduler): void {
                $active = SurveyTemplate::query()->where('trigger_event', SurveyTriggerEvent::SemiAnnualActiveClient->value)->where('is_active', true)->exists();

                if (! $active || ! class_exists(Account::class)) {
                    return;
                }

                $scheduled = 0;

                Account::query()->where('status', 'active')->whereNotNull('contact_id')->each(function (Account $account) use ($scheduler, &$scheduled): void {
                    $recent = SurveyDispatch::query()
                        ->where('customer_id', $account->contact_id)
                        ->where('trigger_event', SurveyTriggerEvent::SemiAnnualActiveClient->value)
                        ->where('created_at', '>=', now()->subDays(180))
                        ->exists();

                    if ($recent) {
                        return;
                    }

                    $scheduler->handle(new SurveyTriggerContext(
                        SurveyTriggerEvent::SemiAnnualActiveClient,
                        (string) $account->contact_id,
                        'crm_account',
                        (string) $account->id,
                        null,
                        ['customer_tier' => $account->tier],
                    ));
                    $scheduled++;
                });

                $this->line("{$tenant->id}: {$scheduled} semi-annual survey(s) evaluated");
            });
        }

        return self::SUCCESS;
    }
}
