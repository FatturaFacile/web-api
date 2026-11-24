<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL of the customer that receives the signed events it subscribed to. The signing secrets are encrypted at rest
 * (they are needed to sign) and never serialised: the secret is shown once, by the call that creates or rotates it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $url
 * @property string $status
 * @property list<string> $events
 * @property string $payload_version
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonInterface|null $previous_secret_expires_at
 * @property int $consecutive_failures
 * @property CarbonInterface|null $disabled_at
 * @property CarbonInterface|null $last_success_at
 * @property CarbonInterface|null $last_failure_at
 */
#[UseFactory(WebhookEndpointFactory::class)]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string SecretPrefix = 'whsec_';

    public const string StatusActive = 'active';

    public const string StatusDisabled = 'disabled';

    protected $fillable = [
        'tenant_id', 'name', 'url', 'description', 'status', 'events', 'payload_version', 'secret', 'secret_hint',
        'previous_secret', 'previous_secret_expires_at', 'consecutive_failures', 'disabled_at', 'disabled_reason',
        'last_success_at', 'last_failure_at', 'created_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::StatusActive;
    }

    /**
     * The secrets a payload is signed with: the current one, and the previous one while its overlap lasts.
     *
     * @return list<string>
     */
    public function signingSecrets(): array
    {
        $secrets = [(string) $this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture()) {
            $secrets[] = (string) $this->previous_secret;
        }

        return $secrets;
    }
}
