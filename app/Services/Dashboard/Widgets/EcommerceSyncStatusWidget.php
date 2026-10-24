<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;

/**
 * Per channel: orders imported so far, the outcome of the latest order pull (imported, failed, ...) and the time of
 * the last successful synchronisation.
 */
class EcommerceSyncStatusWidget extends AbstractDashboardWidget
{
    public function getId(): string
    {
        return 'ecommerce_sync_status';
    }

    public function getType(): string
    {
        return 'data_table';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 4, 'h' => 3];
    }

    public function getData(array $settings = []): array
    {
        $imported = EcommerceMapping::query()
            ->where('entity_type', 'order')
            ->selectRaw('channel_id, COUNT(*) as total')
            ->groupBy('channel_id')
            ->pluck('total', 'channel_id');

        $channels = EcommerceChannel::query()->orderBy('name')->get()->map(function (EcommerceChannel $channel) use ($imported): array {
            $run = $channel->last_sync_summary;

            return [
                'id' => $channel->id,
                'name' => $channel->name,
                'driver' => $channel->driver,
                'is_active' => $channel->is_active,
                'last_sync_at' => $channel->last_sync_at?->toIso8601String(),
                'orders_imported' => (int) ($imported[$channel->id] ?? 0),
                'last_run' => $run === null ? null : [
                    'at' => $run['at'] ?? null,
                    'imported' => (int) ($run['imported'] ?? 0),
                    'updated' => (int) ($run['updated'] ?? 0),
                    'duplicates' => (int) ($run['duplicates'] ?? 0),
                    'failed' => (int) ($run['failed'] ?? 0),
                ],
            ];
        });

        return [
            'channels' => $channels->all(),
            'total_orders_imported' => $channels->sum('orders_imported'),
            'total_failed_last_run' => $channels->sum(fn (array $channel): int => $channel['last_run']['failed'] ?? 0),
        ];
    }
}
