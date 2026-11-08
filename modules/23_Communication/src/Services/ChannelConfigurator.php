<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\Communication\Models\CommunicationChannel;

/**
 * Saves the configuration of a channel and shows it without its secrets. Credentials are stored encrypted. Saving merges:
 * a key that is sent replaces the stored one, a key sent as null is removed, a key left out is kept, so a password does
 * not have to be typed again to change the host. Every change is written to the audit ledger with the names of the keys
 * it touched, never their values.
 */
final readonly class ChannelConfigurator
{
    /**
     * Credential keys that are never shown again once stored.
     *
     * @var list<string>
     */
    public const array Secrets = ['password', 'auth_token', 'bot_token'];

    /**
     * Credential keys each driver understands; any other key is refused.
     *
     * @var array<string, list<string>>
     */
    public const array Keys = [
        'smtp' => ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'],
        'twilio' => ['account_sid', 'auth_token', 'from'],
        'telegram' => ['bot_token'],
        'mail' => [],
        'local' => [],
    ];

    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext, private AuditLedgerInterface $audit) {}

    /**
     * Stores the driver, the switch and the credentials of a channel.
     *
     * @param  array{driver: string, is_enabled?: ?bool, credentials?: ?array<string, ?string>}  $data
     */
    public function save(string $channel, array $data, ?User $user): CommunicationChannel
    {
        $settings = CommunicationChannel::firstOrNew(['tenant_id' => $this->tenantContext->getTenantId(), 'channel' => $channel]);
        $before = $settings->exists ? ['driver' => $settings->driver, 'is_enabled' => $settings->is_enabled, 'credential_keys' => array_keys($settings->credentials ?? [])] : null;

        $allowed = self::Keys[$data['driver']] ?? [];
        $credentials = array_intersect_key($settings->credentials ?? [], array_flip($allowed));
        $touched = [];

        foreach (($data['credentials'] ?? []) as $key => $value) {
            $touched[] = $key;

            if ($value === null || $value === '') {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = (string) $value;
            }
        }

        $settings->fill(['driver' => $data['driver'], 'is_enabled' => $data['is_enabled'] ?? true]);
        $settings->credentials = $credentials === [] ? null : $credentials;
        $settings->save();

        $this->audit->record(
            $before === null ? 'COMMUNICATION_CHANNEL_CONFIGURED' : 'COMMUNICATION_CHANNEL_UPDATED',
            $settings,
            $before,
            ['driver' => $settings->driver, 'is_enabled' => $settings->is_enabled, 'credential_keys' => array_keys($credentials), 'credential_keys_changed' => $touched, 'changed_by' => $user?->id],
            'MODULE_23_COMMUNICATION'
        );

        return $settings;
    }

    /**
     * The configuration as the API shows it: everything but the secrets, and which secrets are set.
     *
     * @return array<string, mixed>
     */
    public function present(CommunicationChannel $channel): array
    {
        $credentials = $channel->credentials ?? [];

        return [
            'id' => (string) $channel->id,
            'channel' => $channel->channel,
            'driver' => $channel->driver,
            'is_enabled' => (bool) $channel->is_enabled,
            'has_credentials' => $credentials !== [],
            'settings' => (object) array_diff_key($credentials, array_flip(self::Secrets)),
            'secrets_set' => array_values(array_intersect(array_keys($credentials), self::Secrets)),
        ];
    }
}
