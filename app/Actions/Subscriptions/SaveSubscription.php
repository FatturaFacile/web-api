<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Models\Subscription;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionBilling\Services\LegacySubscriptionMigrator;

/**
 * Creates a carrier subscription row (`subscriptions`) for the records that reference that table by foreign key
 * (the recurring services of the workshop), together with its copy in the subscription engine under the same id: the
 * engine bills it, and its invoices keep pointing at the carrier through `invoices.subscription_id`.
 */
class SaveSubscription
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
        private readonly LegacySubscriptionMigrator $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data): Subscription {
            $computed = $this->totals->calculate($data['items']);
            unset($data['items']);

            $subscription = new Subscription;
            $subscription->fill($data);
            $subscription->subscription_number = $this->numbering->next('subscription', (int) date('Y', strtotime((string) $data['start_date'])))['number'];
            $subscription->status = 'active';
            $subscription->next_billing_date = $data['start_date'];
            $subscription->save();

            $subscription->items()->createMany($computed['items']);
            $this->events->record($subscription, 'created');
            $this->engine->copyOne($subscription->refresh());

            return $subscription->load(['items', 'contact']);
        });
    }
}
