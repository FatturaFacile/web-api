<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Openapi.it, Global Invoice API. The company must exist as an IT configuration with customer_invoice enabled, and
 * supplier_invoice plus the provider recipient code registered on Fatture e Corrispettivi to receive invoices.
 */
final class OpenapiGateway extends ApiGateway
{
    /**
     * Technical SDI states mapped to receipts. SENT and BOOKED are intermediate.
     *
     * @var array<string, string>
     */
    private const array EventByStatus = [
        'DELIVERED' => 'RC',
        'NOT_DELIVERED' => 'MC',
        'ACCEPTED' => 'NEC1',
        'REJECTED' => 'NEC2',
        'TERMS_EXPIRED' => 'DT',
        'ERROR' => 'NS',
    ];

    public function name(): string
    {
        return 'openapi';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        $response = $this->call(fn () => $this->client($tenant)
            ->acceptJson()
            ->withBody($xml, 'application/xml')
            ->post('/IT-invoices'));

        return ['uuid' => (string) $response->json('data.id'), 'response' => (array) $response->json('data')];
    }

    public function poll(Tenant $tenant): array
    {
        $items = [];

        foreach ($this->pendingTransmissions($tenant) as $id) {
            $details = (array) $this->call(fn () => $this->client($tenant)->acceptJson()->get("/IT-invoices/{$id}"))->json('data.details');
            $event = self::EventByStatus[strtoupper((string) ($details['sdi_status'] ?? ''))] ?? null;

            if ($event !== null) {
                $items[] = SdiInboundItem::notification($id, $event, $details['sdi_message'] ?? null);
            }
        }

        $received = $this->call(fn () => $this->client($tenant)->acceptJson()->get('/IT-invoices', [
            'fiscal_id' => preg_replace('/\D/', '', (string) $tenant->vat_number) ?: $tenant->fiscal_code,
            'direction' => 'incoming',
            'state' => 'RECEIVED',
            'limit' => 100,
        ]))->json('data', []);

        foreach ((array) $received as $invoice) {
            $id = (string) ($invoice['id'] ?? '');

            if ($id === '' || $this->alreadyImported($id)) {
                continue;
            }

            // This API picks the format from the Content-Type of the request, even on a GET.
            $xml = $this->call(fn () => $this->client($tenant)
                ->withHeaders(['Content-Type' => 'application/xml', 'Accept' => 'application/xml'])
                ->get("/IT-invoices/{$id}"))->body();

            $items[] = SdiInboundItem::invoice((string) ($invoice['details']['sdi_filename'] ?? "{$id}.xml"), $xml, $id);
        }

        return $items;
    }

    /**
     * Openapi callbacks carry no signature, so the secret travels in the URL registered on the provider console.
     */
    public function verifyWebhook(Tenant $tenant, Request $request): bool
    {
        $secret = (string) $tenant->sdi_webhook_secret;

        return $secret !== '' && hash_equals($secret, (string) $request->query('token'));
    }

    private function client(Tenant $tenant): PendingRequest
    {
        return Http::baseUrl($this->isSandbox($tenant) ? 'https://test.invoice.openapi.com' : 'https://invoice.openapi.com')
            ->timeout(self::RequestTimeoutSeconds)
            ->withToken((string) $this->credentials($tenant, 'token')['token']);
    }
}
