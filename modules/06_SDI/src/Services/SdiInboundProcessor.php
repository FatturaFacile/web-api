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
use Modules\SDI\Contracts\RereadsInboundMessages;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Applies what a channel returned: receipts update the invoices, supplier invoices enter the passive cycle, messages
 * that could not be read or trusted are stored in quarantine with the whole original message.
 */
final class SdiInboundProcessor
{
    public const string QuarantineEvent = 'QUARANTINED';

    public const string QuarantineStatus = 'quarantined';

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

    public static function quarantineFingerprint(string $provider, string $tenantId, string $raw): string
    {
        return hash('sha256', "{$provider}|quarantine|{$tenantId}|".hash('sha256', $raw));
    }

    /**
     * Items of the same message share a cursor: the cursor is acknowledged only once all of them are applied, so a
     * failure halfway through a message leaves the whole message to be read again.
     *
     * @return array{notifications: int, invoices: int, rejected: int}
     *
     * @throws SdiProviderException when the channel cannot be reached; items not yet acknowledged are read again later
     */
    public function poll(Tenant $tenant): array
    {
        $gateway = $this->gateways->for($tenant);

        return $this->tenantContext->runWithTenant($tenant, function () use ($tenant, $gateway): array {
            $totals = ['notifications' => 0, 'invoices' => 0, 'rejected' => 0];
            $pending = [];

            foreach ($gateway->poll($tenant) as $item) {
                if ($pending !== [] && $item->cursor !== end($pending)->cursor) {
                    $this->acknowledgeAll($tenant, $gateway, $pending);
                    $pending = [];
                }

                $totals = $this->apply($tenant, $gateway, $item, $totals);
                $pending[] = $item;
            }

            $this->acknowledgeAll($tenant, $gateway, $pending);

            $tenant->update(['sdi_last_poll_at' => now()]);

            return $totals;
        });
    }

    /**
     * Read a quarantined message again. When nothing in it fails any more its items are applied (each one is idempotent)
     * and the log is marked processed; otherwise it stays in quarantine with the new reasons.
     *
     * @throws ValidationException when the log is not a quarantined message of this tenant or its channel cannot read it
     */
    public function retryQuarantined(Tenant $tenant, EiInboundLog $log): EiInboundLog
    {
        $gateway = $this->gateways->for($tenant);
        $raw = base64_decode((string) $log->raw_content, true);

        if ($log->tenant_id !== $tenant->id || $log->status !== self::QuarantineStatus || $raw === false || $raw === ''
            || $log->provider !== $gateway->name() || ! $gateway instanceof RereadsInboundMessages) {
            throw ValidationException::withMessages(['log' => __('sdi.quarantine_not_retryable')]);
        }

        return $this->tenantContext->runWithTenant($tenant, function () use ($tenant, $gateway, $log, $raw): EiInboundLog {
            $items = $gateway->reread($tenant, $raw, (string) $log->source_reference);
            $totals = ['notifications' => 0, 'invoices' => 0, 'rejected' => 0];
            $failing = null;

            foreach ($items as $item) {
                if ($item->kind === SdiInboundItem::KindQuarantine) {
                    $failing = $item;

                    continue;
                }

                $totals = $this->apply($tenant, $gateway, $item, $totals);
            }

            $log->update($failing === null ? [
                'status' => 'processed',
                'error' => null,
                'processed_at' => now(),
                'attempts' => $log->attempts + 1,
                'payload' => [...(array) $log->payload, 'retry_totals' => $totals],
            ] : [
                'error' => mb_substr(implode(' | ', $failing->reasons), 0, 1000),
                'signature_verified' => $failing->signatureVerified,
                'attempts' => $log->attempts + 1,
                'payload' => [...(array) $log->payload, ...$failing->details, 'reasons' => $failing->reasons],
            ]);

            return $log;
        });
    }

    /**
     * @param  list<SdiInboundItem>  $items
     */
    private function acknowledgeAll(Tenant $tenant, SdiGateway $gateway, array $items): void
    {
        foreach ($items as $item) {
            $gateway->acknowledge($tenant, $item);
        }
    }

    /**
     * @param  array{notifications: int, invoices: int, rejected: int}  $totals
     * @return array{notifications: int, invoices: int, rejected: int}
     */
    private function apply(Tenant $tenant, SdiGateway $gateway, SdiInboundItem $item, array $totals): array
    {
        if ($item->kind === SdiInboundItem::KindQuarantine) {
            $this->quarantine($tenant, $gateway, $item);
        }

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
     * Store the whole message. The same message read twice (the cursor did not move, or a retry still fails) updates the
     * stored record instead of adding another one.
     */
    private function quarantine(Tenant $tenant, SdiGateway $gateway, SdiInboundItem $item): void
    {
        $raw = (string) $item->raw;
        $log = EiInboundLog::createOrFirst(['fingerprint' => self::quarantineFingerprint($gateway->name(), $tenant->id, $raw)], [
            'tenant_id' => $tenant->id,
            'provider' => $gateway->name(),
            'event_type' => self::QuarantineEvent,
            'status' => self::QuarantineStatus,
            'source_reference' => $item->sourceReference,
            'raw_content' => base64_encode($raw),
            'signature_verified' => $item->signatureVerified,
            'payload' => [...$item->details, 'reasons' => $item->reasons],
            'error' => mb_substr(implode(' | ', $item->reasons), 0, 1000),
        ]);

        if (! $log->wasRecentlyCreated) {
            $log->update([
                'status' => self::QuarantineStatus,
                'processed_at' => null,
                'signature_verified' => $item->signatureVerified,
                'payload' => [...$item->details, 'reasons' => $item->reasons],
                'error' => mb_substr(implode(' | ', $item->reasons), 0, 1000),
                'attempts' => $log->attempts + 1,
            ]);
        }
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

            if ($item->signatureVerified !== null) {
                EiInboundLog::where('fingerprint', $fingerprint)->update(['signature_verified' => $item->signatureVerified]);
            }

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
                'signature_verified' => $item->signatureVerified,
                'processed_at' => now(),
            ]);

            return false;
        }
    }
}
