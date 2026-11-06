<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Exceptions\SdiProviderException;
use App\Models\EiInboundLog;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;
use Modules\SDI\Services\SdiInboundProcessor;

/**
 * What the REST providers have in common. Their webhooks only wake the poll up, so each provider has one single way of
 * reading receipts and supplier invoices, and a lost webhook costs nothing.
 */
abstract class ApiGateway implements SdiGateway
{
    protected const int RequestTimeoutSeconds = 30;

    /**
     * How long a transmission stays worth asking about: public administrations have 15 days to answer.
     */
    private const int PendingDays = 30;

    public function acknowledge(Tenant $tenant, SdiInboundItem $item): void {}

    /**
     * @return array<string, mixed>
     *
     * @throws SdiProviderException when a credential is missing
     */
    protected function credentials(Tenant $tenant, string ...$keys): array
    {
        $credentials = (array) ($tenant->sdi_api_credentials ?? []);

        foreach ($keys as $key) {
            if (empty($credentials[$key])) {
                throw new SdiProviderException(__('sdi.credentials_missing', ['provider' => $this->name(), 'key' => $key]));
            }
        }

        return $credentials;
    }

    protected function isSandbox(Tenant $tenant): bool
    {
        return $tenant->sdi_environment === 'sandbox';
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws SdiProviderException when the provider is unreachable or answers with an error
     */
    protected function call(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new SdiProviderException(__('sdi.provider_unreachable', ['provider' => $this->name()]), previous: $e);
        }

        if ($response->failed()) {
            throw new SdiProviderException(__('sdi.provider_error', [
                'provider' => $this->name(),
                'status' => $response->status(),
                'error' => mb_substr($response->body(), 0, 500),
            ]));
        }

        return $response;
    }

    /**
     * Identifiers of the transmissions of a tenant still waiting for a final answer.
     *
     * @return list<string>
     */
    protected function pendingTransmissions(Tenant $tenant): array
    {
        return DB::table('sdi_uuid_links')
            ->join('invoice_sdi_metadata', 'invoice_sdi_metadata.invoice_id', '=', 'sdi_uuid_links.invoice_id')
            ->where('sdi_uuid_links.tenant_id', $tenant->id)
            ->where('sdi_uuid_links.provider', $this->name())
            ->where('sdi_uuid_links.created_at', '>=', now()->subDays(self::PendingDays))
            ->whereIn('invoice_sdi_metadata.sdi_status', ['sending', 'delivered'])
            ->pluck('sdi_uuid_links.sdi_uuid')
            ->all();
    }

    /**
     * Providers charge for downloads, so a supplier invoice already imported is not fetched again.
     */
    protected function alreadyImported(string $externalId): bool
    {
        return EiInboundLog::where('fingerprint', SdiInboundProcessor::invoiceFingerprint($this->name(), $externalId))->exists();
    }
}
