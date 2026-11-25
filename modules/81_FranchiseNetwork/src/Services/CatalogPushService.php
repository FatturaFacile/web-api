<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\FranchiseNetwork\Contracts\CatalogSyncGatewayInterface;
use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\PushStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\TargetStatus;
use Modules\FranchiseNetwork\Domain\Support\PricePolicy;
use Modules\FranchiseNetwork\Events\CatalogPushDispatched;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\CatalogPush;
use Modules\FranchiseNetwork\Models\CatalogPushTarget;
use Modules\FranchiseNetwork\Models\FranchiseStore;

/**
 * Central distribution from the franchisor to its stores: catalogue (items, variants, asset metadata, recipes / bills of materials,
 * prices), price lists, promotions and loyalty campaigns, to every active store or to a selection. The state of each store is tracked
 * on its own delivery: pending, synced, acknowledged by the franchisee, or failed (retried with a growing delay, up to the maximum).
 */
final class CatalogPushService
{
    public function __construct(private readonly CatalogSyncGatewayInterface $gateway) {}

    /**
     * @param  array<string, mixed>  $data  push_type, title, payload, target_mode, store_ids, effective_from
     */
    public function create(array $data, ?string $userId): CatalogPush
    {
        $stores = $this->resolveStores((string) $data['target_mode'], $data['store_ids'] ?? null);

        return DB::transaction(function () use ($data, $stores, $userId): CatalogPush {
            $push = CatalogPush::query()->create([
                'push_type' => $data['push_type'],
                'title' => $data['title'],
                'payload' => $data['payload'],
                'target_mode' => $data['target_mode'],
                'store_ids' => $data['target_mode'] === 'selected' ? $stores->pluck('id')->all() : null,
                'status' => PushStatus::Draft->value,
                'effective_from' => $data['effective_from'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($stores as $store) {
                CatalogPushTarget::query()->create(['push_id' => $push->id, 'store_id' => $store->id, 'status' => TargetStatus::Pending->value]);
            }

            return $push->refresh();
        });
    }

    /**
     * One click: delivers the pending and the failed deliveries of the push.
     */
    public function dispatch(CatalogPush $push, ?string $userId): CatalogPush
    {
        $targets = $push->targets()->with('store')->whereIn('status', [TargetStatus::Pending->value, TargetStatus::Failed->value])->get();

        foreach ($targets as $target) {
            $this->deliver($push, $target);
        }

        $push->update(['dispatched_at' => $push->dispatched_at ?? now(), 'status' => $this->aggregate($push)->value, 'updated_by' => $userId]);
        event(new CatalogPushDispatched((string) $push->tenant_id, (string) $push->id));

        return $push->refresh();
    }

    /**
     * Re-delivers the failed stores of a push whose attempts are not exhausted.
     */
    public function retryFailed(CatalogPush $push, ?string $userId): CatalogPush
    {
        $max = (int) config('franchise_network.push.max_attempts');

        if (! $push->targets()->where('status', TargetStatus::Failed->value)->where('attempts', '<', $max)->exists()) {
            throw new FranchiseNetworkException('push_nothing_to_retry', 409);
        }

        foreach ($push->targets()->with('store')->where('status', TargetStatus::Failed->value)->where('attempts', '<', $max)->get() as $target) {
            $this->deliver($push, $target);
        }

        $push->update(['status' => $this->aggregate($push)->value, 'updated_by' => $userId]);

        return $push->refresh();
    }

    /**
     * Scheduled retry of the failed deliveries that are due.
     */
    public function retryDue(): int
    {
        $count = 0;
        $pushes = CatalogPush::query()->whereIn('id', CatalogPushTarget::query()->where('status', TargetStatus::Failed->value)->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())->select('push_id'))->get();

        foreach ($pushes as $push) {
            $due = $push->targets()->with('store')->where('status', TargetStatus::Failed->value)->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())->get();

            foreach ($due as $target) {
                $this->deliver($push, $target);
                $count++;
            }

            $push->update(['status' => $this->aggregate($push)->value]);
        }

        return $count;
    }

    /**
     * The franchisee company confirms it received and applied the push of its store.
     */
    public function acknowledge(CatalogPushTarget $target): CatalogPushTarget
    {
        if ($target->status === TargetStatus::Acknowledged) {
            return $target;
        }

        if ($target->status !== TargetStatus::Synced) {
            throw new FranchiseNetworkException('push_not_synced', 409);
        }

        $target->update(['status' => TargetStatus::Acknowledged->value, 'acknowledged_at' => now()]);

        return $target->refresh();
    }

    /**
     * The payload as the store receives it: prices adjusted to its jurisdiction.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(CatalogPush $push, FranchiseStore $store): array
    {
        return PricePolicy::applyToPayload($push->payload, Jurisdiction::tryFrom(strtoupper((string) $store->country)) ?? Jurisdiction::It);
    }

    private function deliver(CatalogPush $push, CatalogPushTarget $target): void
    {
        $store = $target->store ?? FranchiseStore::query()->withoutGlobalScope(TenantScope::class)->findOrFail($target->store_id);
        $attempts = $target->attempts + 1;

        try {
            $this->gateway->deliver($push, $store, $this->payloadFor($push, $store));
            $target->update(['status' => TargetStatus::Synced->value, 'attempts' => $attempts, 'last_error' => null, 'synced_at' => now(), 'next_retry_at' => null]);
        } catch (FranchiseNetworkException $exception) {
            $retry = $attempts < (int) config('franchise_network.push.max_attempts') ? now()->addMinutes((int) config('franchise_network.push.retry_minutes') * $attempts) : null;
            $target->update(['status' => TargetStatus::Failed->value, 'attempts' => $attempts, 'last_error' => $exception->errorCode(), 'next_retry_at' => $retry]);
        }
    }

    private function aggregate(CatalogPush $push): PushStatus
    {
        $statuses = $push->targets()->pluck('status')->map(static fn ($status): string => $status instanceof TargetStatus ? $status->value : (string) $status);
        $failed = $statuses->filter(static fn (string $status): bool => $status === TargetStatus::Failed->value)->count();

        return match (true) {
            $statuses->isEmpty() || $statuses->contains(TargetStatus::Pending->value) => PushStatus::Draft,
            $failed === 0 => PushStatus::Completed,
            $failed === $statuses->count() => PushStatus::Failed,
            default => PushStatus::Partial,
        };
    }

    /**
     * @param  list<string>|null  $storeIds
     * @return Collection<int, FranchiseStore>
     */
    private function resolveStores(string $mode, ?array $storeIds): Collection
    {
        $stores = FranchiseStore::query()
            ->whereIn('status', [StoreStatus::Active->value, StoreStatus::Suspended->value])
            ->when($mode === 'selected', fn ($query) => $query->whereIn('id', $storeIds ?? []))
            ->orderBy('code')
            ->get();

        if ($stores->isEmpty()) {
            throw new FranchiseNetworkException('push_no_targets');
        }

        return $stores;
    }
}
