<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Invoicetronic API. The sandbox is selected by the API key itself, so the environment of the tenant plays no part.
 */
final class InvoicetronicGateway extends ApiGateway
{
    /**
     * Age limit of a signed webhook, against replays.
     */
    private const int WebhookToleranceSeconds = 300;

    /**
     * @var array<string, string>
     */
    private const array EventByState = [
        'Consegnato' => 'RC',
        'NonConsegnato' => 'MC',
        'Scartato' => 'NS',
        'AccettatoDalDestinatario' => 'NEC1',
        'RifiutatoDalDestinatario' => 'NEC2',
        'ImpossibilitaDiRecapito' => 'AT',
        'DecorrenzaTermini' => 'DT',
        'AttestazioneTrasmissioneFattura' => 'AT',
    ];

    public function name(): string
    {
        return 'invoicetronic';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        $query = http_build_query(['validate' => 'false', 'signature' => $tenant->sdi_sign_outgoing ? 'Force' : 'Auto']);

        $response = $this->call(fn () => $this->client($tenant)
            ->withHeaders(['Idempotency-Key' => $fileName])
            ->withBody($xml, 'application/xml')
            ->post("/send/xml?{$query}"));

        return ['uuid' => (string) $response->json('id'), 'response' => array_diff_key((array) $response->json(), ['payload' => true])];
    }

    public function poll(Tenant $tenant): array
    {
        $items = [];
        $updates = (array) $this->call(fn () => $this->client($tenant)->get('/update', ['unread' => 'true', 'page_size' => 100]))->json();

        foreach ($updates as $update) {
            $event = self::EventByState[(string) ($update['state'] ?? '')] ?? null;

            if ($event !== null && isset($update['send_id'])) {
                $items[] = SdiInboundItem::notification((string) $update['send_id'], $event, $this->messageOf((array) $update));
            }
        }

        $received = (array) $this->call(fn () => $this->client($tenant)->get('/receive', [
            'unread' => 'true',
            'include_payload' => 'true',
            'page_size' => 100,
        ]))->json();

        foreach ($received as $invoice) {
            $id = (string) ($invoice['id'] ?? '');

            if ($id !== '' && ! $this->alreadyImported($id)) {
                $items[] = SdiInboundItem::invoice((string) ($invoice['file_name'] ?? "{$id}.xml"), $this->payloadOf((array) $invoice), $id);
            }
        }

        return $items;
    }

    /**
     * The Invoicetronic-Signature header holds t=<timestamp>,v1=<HMAC-SHA256 of "<timestamp>.<raw body>">.
     */
    public function verifyWebhook(Tenant $tenant, Request $request): bool
    {
        $secret = (string) $tenant->sdi_webhook_secret;
        parse_str(str_replace(',', '&', (string) $request->header('Invoicetronic-Signature')), $signature);

        $timestamp = (string) ($signature['t'] ?? '');
        $given = (string) ($signature['v1'] ?? '');

        if ($secret === '' || $given === '' || abs(now()->timestamp - (int) $timestamp) > self::WebhookToleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $given);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function payloadOf(array $invoice): string
    {
        $payload = (string) ($invoice['payload'] ?? '');

        return ($invoice['encoding'] ?? 'Xml') === 'Base64' ? (string) base64_decode($payload, true) : $payload;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function messageOf(array $update): ?string
    {
        $errors = $update['errors'] ?? [];
        $parts = array_filter([$update['description'] ?? null, $errors === [] ? null : json_encode($errors)]);

        return $parts === [] ? null : mb_substr(implode(' | ', $parts), 0, 1000);
    }

    private function client(Tenant $tenant): PendingRequest
    {
        return Http::baseUrl('https://api.invoicetronic.com/v1')
            ->timeout(self::RequestTimeoutSeconds)
            ->acceptJson()
            ->withBasicAuth((string) $this->credentials($tenant, 'api_key')['api_key'], '');
    }
}
