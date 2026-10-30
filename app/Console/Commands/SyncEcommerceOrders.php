<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ecommerce\PullEcommerceOrdersAction;
use App\Models\EcommerceChannel;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class SyncEcommerceOrders extends Command
{
    protected $signature = 'ecommerce:sync-orders';

    protected $description = 'Import the new orders of every active e-commerce channel of every tenant';

    public function handle(TenantContext $tenantContext, PullEcommerceOrdersAction $pull): int
    {
        $totals = ['imported' => 0, 'updated' => 0, 'duplicates' => 0, 'failed' => 0];
        $channelFailures = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $pull, &$totals, &$channelFailures): void {
            $tenantContext->runWithTenant($tenant, function () use ($pull, &$totals, &$channelFailures): void {
                $channels = EcommerceChannel::query()->where('is_active', true)->where('sync_orders_enabled', true)->get();

                foreach ($channels as $channel) {
                    try {
                        foreach ($pull->execute($channel) as $key => $count) {
                            $totals[$key] += $count;
                        }
                    } catch (Throwable $e) {
                        $channelFailures++;
                        $this->warn("{$channel->name}: {$e->getMessage()}");
                    }
                }
            });
        });

        $this->info("Orders imported: {$totals['imported']}; updated: {$totals['updated']}; duplicates: {$totals['duplicates']}; failed: {$totals['failed']}; channels failed: {$channelFailures}");

        return $channelFailures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
