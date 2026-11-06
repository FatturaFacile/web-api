<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Actions\Purchases\ProcessIncomingInvoiceWebhook;
use App\Actions\Sdi\ProcessSdiNotification;
use App\Exceptions\SdiProviderException;
use App\Models\EiInboundLog;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Applies what a channel returned: receipts update the invoices, supplier invoices enter the passive cycle.
 */
final class SdiInboundProcessor
{
    public function __construct(
        private readonly SdiGatewayManager $gateways,
        private readonly TenantContext $tenantContext,
        private readonly ProcessSdiNotification $notifications,
        private readonly ProcessIncomingInvoiceWebhook $incomingInvoices,
        private readonly P7mExtractor $p7m,
    ) {}

    public static function invoiceFingerprint(string $provider, string $externalId): string
    {
        return hash('sha256', "{$provider}|incoming-file|{$externalId}");
    }

    /**
     * @return array{notifications: int, invoices: int, rejected: int}
     *
     * @throws SdiProviderException when the channel cannot be reached; items not yet acknowledged are read again later
     */
    public function poll(Tenant $tenant): array
    {
        $gateway = $this->gateways->for($tenant);

        return $this->tenantContext->runWithTenant($tenant, function () use ($tenant, $gateway): array {
            $totals = ['notifications' => 0, 'invoices' => 0, 'rejected' => 0];

            foreach ($gateway->poll($tenant) as $item) {
                $totals = $this->apply($tenant, $gateway, $item, $totals);
                $gateway->acknowledge($tenant, $item);
            }

            $tenant->update(['sdi_last_poll_at' => now()]);

            return $totals;
        });
    }

    /**
     * @param  array{notifications: int, invoices: int, rejected: int}  $totals
     * @return array{notifications: int, invoices: int, rejected: int}
     */
    private function apply(Tenant $tenant, SdiGateway $gateway, SdiInboundItem $item, array $totals): array
    {
        if ($item->kind === SdiInboundItem::KindNotification) {
            $this->notifications->execute([
                'provider' => $gateway->name(),
                'uuid' => (string) $item->uuid,
                'event_type' => (string) $item->eventType,
                'message' => $item->message,
            ]);
            $totals['notifications']++;
        }

        if ($item->kind === SdiInboundItem::KindInvoice) {
            $imported = $this->importInvoice($tenant, $gateway, $item);
            $totals[$imported ? 'invoices' : 'rejected'] += $imported === null ? 0 : 1;
        }

        return $totals;
    }

    /**
     * A malformed file or one addressed to somebody else is logged and left behind: it must not block the mailbox.
     *
     * @return bool|null true when imported, false when refused, null when already known
     */
    private function importInvoice(Tenant $tenant, SdiGateway $gateway, SdiInboundItem $item): ?bool
    {
        $fingerprint = self::invoiceFingerprint($gateway->name(), (string) $item->externalId);

        if (EiInboundLog::where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        try {
            $xml = $this->p7m->extract((string) $item->xml);
            $this->incomingInvoices->execute($tenant, $xml, $item->fileName, $gateway->name(), $fingerprint);

            return true;
        } catch (InvalidArgumentException|ValidationException $e) {
            EiInboundLog::create([
                'tenant_id' => $tenant->id,
                'provider' => $gateway->name(),
                'event_type' => 'INVOICE_RECEIVED',
                'fingerprint' => $fingerprint,
                'payload' => ['file_name' => $item->fileName],
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ]);

            return false;
        }
    }
}
