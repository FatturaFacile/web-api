<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * A-Cube API, gov-it. Supplier invoices need supplier_invoice_enabled on the business registry configuration and the
 * provider recipient code registered on Fatture e Corrispettivi.
 */
final class AcubeGateway extends ApiGateway
{
    private const int TokenLifetimeHours = 23;

    private const int MaximumPages = 5;

    /**
     * Notification types that change an invoice. NE is resolved from the outcome inside the notification.
     *
     * @var list<string>
     */
    private const array Handled = ['RC', 'NS', 'MC', 'DT', 'AT'];

    public function name(): string
    {
        return 'acube';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        $headers = $tenant->sdi_sign_outgoing ? ['X-SignInvoice' => 'true'] : [];

        $response = $this->call(fn () => $this->client($tenant)
            ->withHeaders($headers)
            ->acceptJson()
            ->withBody($xml, 'application/xml')
            ->post('/invoices'));

        return ['uuid' => (string) $response->json('uuid'), 'response' => (array) $response->json()];
    }

    public function poll(Tenant $tenant): array
    {
        $items = [];

        foreach ($this->pendingTransmissions($tenant) as $uuid) {
            $notifications = (array) $this->call(fn () => $this->client($tenant)->acceptJson()->get("/invoices/{$uuid}/notifications"))->json();

            foreach ($notifications as $notification) {
                $event = $this->eventOf((array) $notification);

                if ($event !== null) {
                    $items[] = SdiInboundItem::notification($uuid, $event, $this->messageOf((array) $notification));
                }
            }
        }

        return [...$items, ...$this->supplierInvoices($tenant)];
    }

    /**
     * Webhooks are configured with authentication_type header and authentication_key Bearer, and send the secret of the
     * tenant as a bearer token. The Ed25519 HTTP signature of A-Cube is not checked: it needs the sodium extension.
     */
    public function verifyWebhook(Tenant $tenant, Request $request): bool
    {
        $secret = (string) $tenant->sdi_webhook_secret;

        return $secret !== '' && hash_equals($secret, (string) $request->bearerToken());
    }

    /**
     * @return list<SdiInboundItem>
     */
    private function supplierInvoices(Tenant $tenant): array
    {
        $items = [];

        for ($page = 1; $page <= self::MaximumPages; $page++) {
            $invoices = (array) $this->call(fn () => $this->client($tenant)->acceptJson()->get('/invoices', [
                'marking' => 'received',
                'downloaded' => 'false',
                'page' => $page,
                'itemsPerPage' => 30,
            ]))->json();

            foreach ($invoices as $invoice) {
                $uuid = (string) ($invoice['uuid'] ?? '');

                if ($uuid !== '' && ! $this->alreadyImported($uuid)) {
                    $xml = $this->call(fn () => $this->client($tenant)->accept('application/xml')->get("/invoices/{$uuid}"))->body();
                    $items[] = SdiInboundItem::invoice((string) ($invoice['filename'] ?? "{$uuid}.xml"), $xml, $uuid);
                }
            }

            if (count($invoices) < 30) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function eventOf(array $notification): ?string
    {
        $type = (string) ($notification['type'] ?? '');

        if ($type === 'NE') {
            $outcome = (string) json_encode($notification['message'] ?? []);

            return str_contains($outcome, 'EC02') ? 'NEC2' : (str_contains($outcome, 'EC01') ? 'NEC1' : null);
        }

        return in_array($type, self::Handled, true) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function messageOf(array $notification): ?string
    {
        $message = $notification['message'] ?? null;

        return $message === null ? null : mb_substr(is_string($message) ? $message : (string) json_encode($message), 0, 1000);
    }

    private function client(Tenant $tenant): PendingRequest
    {
        return Http::baseUrl($this->isSandbox($tenant) ? 'https://api-sandbox.acubeapi.com' : 'https://api.acubeapi.com')
            ->timeout(self::RequestTimeoutSeconds)
            ->withToken($this->token($tenant));
    }

    /**
     * Tokens last 24 hours and A-Cube asks not to request more than one a day.
     */
    private function token(Tenant $tenant): string
    {
        $credentials = $this->credentials($tenant, 'email', 'password');

        return Cache::remember("sdi:acube:token:{$tenant->id}", now()->addHours(self::TokenLifetimeHours), fn (): string => (string) $this->call(
            fn () => Http::timeout(self::RequestTimeoutSeconds)->acceptJson()->post('https://common.api.acubeapi.com/login', [
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'environment' => $this->isSandbox($tenant) ? 'sandbox' : 'production',
            ])
        )->json('token'));
    }
}
