<?php

declare(strict_types=1);

namespace App\Actions\Ecommerce;

use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Models\Tenant;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Handles a verified webhook delivery inside the tenant of its channel. Order events are imported idempotently, so
 * retries and duplicate deliveries of the platform are harmless.
 */
class ProcessEcommerceWebhook
{
    public function __construct(
        private readonly EcommerceManager $manager,
        private readonly ImportEcommerceOrder $import,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{events: int, imported: int, updated: int, duplicates: int}
     *
     * @throws EcommerceException
     */
    public function execute(EcommerceChannel $channel, Request $request): array
    {
        return $this->tenantContext->runWithTenant(Tenant::findOrFail($channel->tenant_id), function () use ($channel, $request): array {
            $summary = ['events' => 0, 'imported' => 0, 'updated' => 0, 'duplicates' => 0];

            foreach ($this->manager->driver($channel)->handleWebhook($request) as $event) {
                $summary['events']++;

                if ($event['type'] !== 'order') {
                    continue;
                }

                match ($this->import->execute($channel, $event['order'])['status']) {
                    ImportEcommerceOrder::IMPORTED => $summary['imported']++,
                    ImportEcommerceOrder::UPDATED => $summary['updated']++,
                    default => $summary['duplicates']++,
                };
            }

            return $summary;
        });
    }
}
