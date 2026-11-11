<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use App\Models\Tenant;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Jobs\DeliverWebhookJob;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\Notifications\WebhookEndpointDisabledNotification;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;

/**
 * Every change to an endpoint goes through here, so each one is audit-logged (never with a secret) and each rule
 * (https and public addresses only, known events only, one active chain of deliveries) lives in one place.
 */
final class WebhookEndpointManager
{
    private const string ModuleCode = 'MODULE_29_INTEGRATIONS';

    /**
     * Roles that manage integrations (config/permissions.php) and are told when an endpoint is disabled.
     *
     * @var list<string>
     */
    private const array NotifiedRoles = ['owner', 'admin'];

    public function __construct(
        private readonly AuditLedgerInterface $audit,
        private readonly WebhookUrlGuard $guard,
    ) {}

    /**
     * @param  array{name: string, url: string, description?: ?string, events: list<string>, payload_version?: ?string}  $data
     * @return array{endpoint: WebhookEndpoint, secret: string} the secret is not recoverable later
     *
     * @throws ValidationException
     */
    public function create(array $data, ?string $userId): array
    {
        $this->assertUrl($data['url']);

        if (WebhookEndpoint::query()->count() >= (int) config('webhooks.max_endpoints_per_tenant')) {
            throw ValidationException::withMessages(['url' => __('webhooks.too_many_endpoints', ['max' => config('webhooks.max_endpoints_per_tenant')])]);
        }

        $secret = $this->newSecret();

        $endpoint = WebhookEndpoint::create([
            'name' => $data['name'],
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'status' => WebhookEndpoint::StatusActive,
            'events' => array_values(array_unique($data['events'])),
            'payload_version' => $data['payload_version'] ?? config('webhooks.default_payload_version'),
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
            'created_by' => $userId,
        ]);

        $this->record('INTEGRATIONS_WEBHOOK_ENDPOINT_CREATED', $endpoint, null, $this->auditView($endpoint));

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * @param  array{name?: string, url?: string, description?: ?string, events?: list<string>, payload_version?: string}  $data
     *
     * @throws ValidationException
     */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        if (isset($data['url']) && $data['url'] !== $endpoint->url) {
            $this->assertUrl($data['url']);
        }

        $before = $this->auditView($endpoint);

        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        $endpoint->update($data);

        $this->record('INTEGRATIONS_WEBHOOK_ENDPOINT_UPDATED', $endpoint, $before, $this->auditView($endpoint));

        return $endpoint;
    }

    public function delete(WebhookEndpoint $endpoint): void
    {
        $before = $this->auditView($endpoint);

        $this->record('INTEGRATIONS_WEBHOOK_ENDPOINT_DELETED', $endpoint, $before, null);
        $endpoint->delete();
    }

    /**
     * Gives the endpoint a new secret. The previous one keeps signing (next to the new one) for $overlapHours, so the
     * receiver can switch without losing a delivery.
     *
     * @return string the new secret, shown only here
     */
    public function rotateSecret(WebhookEndpoint $endpoint, int $overlapHours): string
    {
        $overlapHours = max(0, min($overlapHours, (int) config('webhooks.max_secret_overlap_hours')));
        $secret = $this->newSecret();

        $endpoint->update([
            'previous_secret' => $overlapHours > 0 ? $endpoint->secret : null,
            'previous_secret_expires_at' => $overlapHours > 0 ? now()->addHours($overlapHours) : null,
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
        ]);

        $this->record('INTEGRATIONS_WEBHOOK_SECRET_ROTATED', $endpoint, null, [...$this->auditView($endpoint), 'overlap_hours' => $overlapHours]);

        return $secret;
    }

    public function enable(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $this->assertUrl((string) $endpoint->url);

        $endpoint->update(['status' => WebhookEndpoint::StatusActive, 'consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]);

        $this->record('INTEGRATIONS_WEBHOOK_ENDPOINT_ENABLED', $endpoint, null, $this->auditView($endpoint));

        return $endpoint;
    }

    /**
     * Disables the endpoint. Its deliveries that are still waiting become dead letters (redeliverable once it is
     * enabled again) so nothing keeps retrying against it. An automatic disable also notifies the owners and
     * admins of the tenant.
     */
    public function disable(WebhookEndpoint $endpoint, string $reason, bool $automatic = false): WebhookEndpoint
    {
        DB::transaction(function () use ($endpoint, $reason): void {
            $endpoint->update(['status' => WebhookEndpoint::StatusDisabled, 'disabled_at' => now(), 'disabled_reason' => $reason]);

            WebhookDelivery::withoutGlobalScopes()
                ->where('tenant_id', $endpoint->tenant_id)
                ->where('webhook_endpoint_id', $endpoint->id)
                ->whereIn('status', [WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying])
                ->update(['status' => WebhookDelivery::StatusDead, 'claimed_at' => null, 'next_attempt_at' => null, 'last_error' => 'The endpoint was disabled.']);
        });

        $this->record($automatic ? 'INTEGRATIONS_WEBHOOK_ENDPOINT_AUTO_DISABLED' : 'INTEGRATIONS_WEBHOOK_ENDPOINT_DISABLED', $endpoint, null, [...$this->auditView($endpoint), 'reason' => $reason]);

        if ($automatic) {
            $this->notifyManagers($endpoint, $reason);
        }

        return $endpoint;
    }

    /**
     * Sends a delivery again, from its first attempt, with the same idempotency key.
     *
     * @throws ValidationException when the delivery is still being sent or its endpoint is disabled
     */
    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || ! $endpoint->isActive()) {
            throw ValidationException::withMessages(['endpoint' => __('webhooks.redeliver_endpoint_disabled')]);
        }

        if (in_array($delivery->status, [WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying], true)) {
            throw ValidationException::withMessages(['status' => __('webhooks.redeliver_in_progress')]);
        }

        $delivery->forceFill(['status' => WebhookDelivery::StatusPending, 'attempts' => 0, 'next_attempt_at' => now(), 'claimed_at' => null, 'delivered_at' => null])->save();

        $this->record('INTEGRATIONS_WEBHOOK_DELIVERY_REDELIVERED', $delivery, null, ['webhook_endpoint_id' => $delivery->webhook_endpoint_id, 'event_name' => $delivery->event_name, 'idempotency_key' => $delivery->idempotency_key]);

        DeliverWebhookJob::dispatch((string) $delivery->id);

        return $delivery;
    }

    public function recordPing(WebhookEndpoint $endpoint, WebhookDelivery $delivery): void
    {
        $this->record('INTEGRATIONS_WEBHOOK_TEST_PING_SENT', $endpoint, null, ['delivery_id' => $delivery->id, 'status' => $delivery->status, 'response_code' => $delivery->last_response_code]);
    }

    private function newSecret(): string
    {
        return WebhookEndpoint::SecretPrefix.Str::random(40);
    }

    /**
     * @throws ValidationException
     */
    private function assertUrl(string $url): void
    {
        try {
            $this->guard->assertSafe($url);
        } catch (UnsafeWebhookUrl $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
    }

    private function notifyManagers(WebhookEndpoint $endpoint, string $reason): void
    {
        $tenant = Tenant::find($endpoint->tenant_id);

        if ($tenant === null) {
            return;
        }

        $recipients = $tenant->users()->wherePivotIn('role', self::NotifiedRoles)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new WebhookEndpointDisabledNotification((string) $endpoint->id, (string) $endpoint->name, $reason));
        }
    }

    /**
     * What the audit trail keeps of an endpoint: never a secret.
     *
     * @return array<string, mixed>
     */
    private function auditView(WebhookEndpoint $endpoint): array
    {
        return $endpoint->only(['id', 'name', 'url', 'description', 'status', 'events', 'payload_version', 'secret_hint', 'previous_secret_expires_at', 'consecutive_failures', 'disabled_reason']);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, WebhookEndpoint|WebhookDelivery $model, ?array $before, ?array $after): void
    {
        $this->audit->record(action: $action, model: $model, payloadBefore: $before, payloadAfter: $after, moduleCode: self::ModuleCode);
    }
}
