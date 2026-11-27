<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Jobs;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SwissEbill\Application\Services\ParticipantDirectoryService;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;

/**
 * Verifies the eBill enrollment of a batch of customers (all customers of the company when the list is empty) in the
 * background. A customer whose lookup fails keeps its previous state; the next run tries again.
 */
final class BulkVerifyCustomerEbillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<string>  $customerIds
     */
    public function __construct(public readonly string $tenantId, public readonly array $customerIds = [], public readonly bool $forceRefresh = false) {}

    public function handle(TenantContext $tenants, ParticipantDirectoryService $directory): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenants->runWithTenant($tenant, function () use ($directory): void {
            Contact::query()
                ->where('type', '!=', 'supplier')
                ->when($this->customerIds !== [], fn ($query) => $query->whereIn('id', $this->customerIds))
                ->orderBy('id')
                ->chunkById(100, function ($contacts) use ($directory): void {
                    foreach ($contacts as $contact) {
                        try {
                            $directory->syncCustomer($contact, $this->forceRefresh);
                        } catch (EbillGatewayException) {
                            continue;
                        } catch (EbillException) {
                            return;
                        }
                    }
                });
        });
    }
}
