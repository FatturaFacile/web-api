<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\SwissEbill\Domain\Enums\EbillConnectionStatus;
use Modules\SwissEbill\Domain\Enums\EbillProtocol;
use Modules\SwissEbill\Domain\Enums\EbillProvider;

/**
 * Swiss eBill configuration of one company: biller id, provider, protocol and the credentials (all secrets encrypted at rest).
 *
 * @property bool $ebill_enabled
 * @property string|null $biller_id
 * @property EbillProvider $provider
 * @property EbillProtocol $channel_protocol
 * @property string|null $api_base_url
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $signing_certificate_pem
 * @property string|null $private_key_pem
 * @property string|null $webhook_secret
 * @property string $webhook_slug
 * @property string $webhook_auth_mode
 * @property string|null $webhook_mtls_fingerprint
 * @property bool $auto_send_on_invoice_issue
 * @property bool $attach_courtesy_pdf
 * @property string $default_ebinterface_version
 * @property Carbon|null $last_connection_tested_at
 * @property EbillConnectionStatus $connection_status
 * @property int|null $last_connection_latency_ms
 * @property string|null $last_connection_message
 */
final class EbillSetting extends BaseEnterpriseModel
{
    public const WEBHOOK_AUTH_MODES = ['hmac_sha256', 'jws_hs256', 'mutual_tls'];

    protected $table = 'ebill_settings';

    /**
     * Secrets never leave the API in a response or in a log.
     *
     * @var list<string>
     */
    protected $hidden = ['client_secret', 'signing_certificate_pem', 'private_key_pem', 'webhook_secret'];

    protected static function booted(): void
    {
        self::creating(static function (self $setting): void {
            if (empty($setting->webhook_slug)) {
                $setting->webhook_slug = Str::lower(Str::random(40));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'ebill_enabled' => 'boolean',
            'provider' => EbillProvider::class,
            'channel_protocol' => EbillProtocol::class,
            'client_secret' => 'encrypted',
            'signing_certificate_pem' => 'encrypted',
            'private_key_pem' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'auto_send_on_invoice_issue' => 'boolean',
            'attach_courtesy_pdf' => 'boolean',
            'last_connection_tested_at' => 'datetime',
            'connection_status' => EbillConnectionStatus::class,
            'last_connection_latency_ms' => 'integer',
        ];
    }

    public function webhookPath(): string
    {
        return '/api/v1/webhooks/swiss-ebill/'.$this->webhook_slug;
    }

    public function isReadyToTransmit(): bool
    {
        return $this->ebill_enabled && $this->biller_id !== null && $this->biller_id !== '';
    }
}
