<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;

/**
 * A document sent for signature to one or more recipients.
 *
 * @property string|null $signable_type
 * @property string|null $signable_id
 * @property string $verification_code
 * @property string $title
 * @property string|null $subject
 * @property string|null $message
 * @property SignatureLevel $required_level
 * @property Jurisdiction $jurisdiction
 * @property RoutingMode $routing_mode
 * @property EnvelopeStatus $status
 * @property string|null $provider_config_id
 * @property ProviderType $provider
 * @property string $locale
 * @property string $document_format
 * @property string|null $file_name
 * @property string|null $original_path
 * @property string|null $original_sha256
 * @property string|null $working_path
 * @property string|null $signed_path
 * @property string|null $signed_sha256
 * @property string|null $external_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_reminder_at
 * @property list<int>|null $reminders_sent
 * @property array<string, mixed>|null $metadata
 * @property string|null $created_by
 */
final class SignatureEnvelope extends BaseEnterpriseModel
{
    protected $table = 'signature_envelopes';

    /**
     * @var list<string>
     */
    protected $hidden = ['original_path', 'working_path', 'signed_path'];

    protected function casts(): array
    {
        return [
            'required_level' => SignatureLevel::class,
            'jurisdiction' => Jurisdiction::class,
            'routing_mode' => RoutingMode::class,
            'status' => EnvelopeStatus::class,
            'provider' => ProviderType::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'reminders_sent' => 'array',
            'metadata' => 'array',
        ];
    }

    public function signableKind(): ?SignableType
    {
        return $this->signable_type === null ? null : SignableType::tryFrom($this->signable_type);
    }

    /**
     * @return HasMany<SignatureRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(SignatureRecipient::class, 'envelope_id')->orderBy('signing_order')->orderBy('created_at');
    }

    /**
     * @return HasMany<SignatureAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(SignatureAuditLog::class, 'envelope_id')->orderBy('sequence');
    }

    /**
     * @return BelongsTo<SignatureProviderConfig, $this>
     */
    public function providerConfig(): BelongsTo
    {
        return $this->belongsTo(SignatureProviderConfig::class, 'provider_config_id');
    }

    /**
     * The recipients that have to act (sign, approve, witness).
     *
     * @return HasMany<SignatureRecipient, $this>
     */
    public function actingRecipients(): HasMany
    {
        return $this->recipients()->where('role', '!=', 'CC_RECIPIENT');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
