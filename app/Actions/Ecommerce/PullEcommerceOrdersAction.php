<?php

declare(strict_types=1);

namespace App\Actions\Ecommerce;

use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Services\Ecommerce\EcommerceManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls the orders of a channel created since the last synchronisation and imports each of them. One broken order is
 * logged and counted as failed without blocking the others; a failing shop connection raises an {@see EcommerceException}.
 */
class PullEcommerceOrdersAction
{
    public function __construct(
        private readonly EcommerceManager $manager,
        private readonly ImportEcommerceOrder $import,
    ) {}

    /**
     * @return array{imported: int, updated: int, duplicates: int, failed: int}
     *
     * @throws EcommerceException
     */
    public function execute(EcommerceChannel $channel, ?Carbon $since = null): array
    {
        $startedAt = now();
        $orders = $this->manager->driver($channel)->pullOrders($since ?? $channel->last_sync_at);
        $summary = ['imported' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0];

        foreach ($orders as $order) {
            try {
                $result = $this->import->execute($channel, $order);
            } catch (Throwable $e) {
                $summary['failed']++;
                Log::warning('E-commerce order {remote_id} of channel {channel} could not be imported: {message}', [
                    'remote_id' => $order['remote_id'] ?? null,
                    'channel' => $channel->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            match ($result['status']) {
                ImportEcommerceOrder::IMPORTED => $summary['imported']++,
                ImportEcommerceOrder::UPDATED => $summary['updated']++,
                default => $summary['duplicates']++,
            };
        }

        $channel->update(['last_sync_summary' => $summary + ['at' => $startedAt->toIso8601String()]]);

        // Failed orders are retried on the next run only while the cursor stays behind them.
        if ($summary['failed'] === 0) {
            $channel->update(['last_sync_at' => $startedAt]);
        }

        return $summary;
    }
}
