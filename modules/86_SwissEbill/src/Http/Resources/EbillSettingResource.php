<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * @mixin EbillSetting
 */
final class EbillSettingResource extends JsonResource
{
    /**
     * @param  string|null  $plainWebhookSecret  shown once, right after it was rotated
     */
    public function __construct($resource, private readonly ?string $plainWebhookSecret = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ebill_enabled' => $this->ebill_enabled,
            'biller_id' => $this->biller_id,
            'provider' => $this->provider->value,
            'channel_protocol' => $this->channel_protocol->value,
            'api_base_url' => $this->api_base_url,
            'client_id' => $this->client_id,
            // Secrets are write-only: the client only learns whether one is stored.
            'has_client_secret' => $this->client_secret !== null && $this->client_secret !== '',
            'has_signing_certificate' => $this->signing_certificate_pem !== null && $this->signing_certificate_pem !== '',
            'has_private_key' => $this->private_key_pem !== null && $this->private_key_pem !== '',
            'has_webhook_secret' => $this->webhook_secret !== null && $this->webhook_secret !== '',
            'webhook_secret' => $this->plainWebhookSecret,
            'webhook_auth_mode' => $this->webhook_auth_mode,
            'webhook_mtls_fingerprint' => $this->webhook_mtls_fingerprint,
            'webhook_url' => url($this->webhookPath()),
            'auto_send_on_invoice_issue' => $this->auto_send_on_invoice_issue,
            'attach_courtesy_pdf' => $this->attach_courtesy_pdf,
            'default_ebinterface_version' => $this->default_ebinterface_version,
            'last_connection_tested_at' => $this->last_connection_tested_at?->toIso8601String(),
            'connection_status' => $this->connection_status->value,
            'last_connection_latency_ms' => $this->last_connection_latency_ms,
            'last_connection_message' => $this->last_connection_message,
        ];
    }
}
