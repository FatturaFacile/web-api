<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Models\EiInboundLog;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ProcessIncomingInvoiceWebhook
{
    public function __construct(
        private readonly ReceivePassiveInvoice $receive,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<int, array{invoice: PassiveInvoice, duplicate: bool}>
     */
    public function execute(Tenant $tenant, string $xml, ?string $fileName, string $provider, string $fingerprint): array
    {
        return DB::transaction(function () use ($tenant, $xml, $fileName, $provider, $fingerprint): array {
            $results = $this->tenantContext->runWithTenant(
                $tenant,
                fn (): array => $this->receive->execute($xml, $fileName)
            );

            EiInboundLog::create([
                'tenant_id' => $tenant->id,
                'provider' => $provider,
                'event_type' => 'INVOICE_RECEIVED',
                'fingerprint' => $fingerprint,
                'payload' => [
                    'file_name' => $fileName,
                    'passive_invoice_ids' => array_map(fn (array $result): string => $result['invoice']->id, $results),
                ],
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return $results;
        }, 5);
    }
}
