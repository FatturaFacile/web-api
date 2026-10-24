<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Models\Subscription;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\DB;

class SaveSubscription
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
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

            return $subscription->load(['items', 'contact']);
        });
    }

    /**
     * Update header fields; replacing items affects future billing runs only.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Subscription $subscription, array $data): Subscription
    {
        return DB::transaction(function () use ($subscription, $data): Subscription {
            if (isset($data['items'])) {
                $subscription->items()->delete();
                $subscription->items()->createMany($this->totals->calculate($data['items'])['items']);
                unset($data['items']);
            }

            $subscription->update($data);
            $this->events->record($subscription, 'updated');

            return $subscription->load(['items', 'contact']);
        });
    }
}
