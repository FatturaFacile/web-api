<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\RecipientRole;
use Modules\ElectronicSignature\Enums\RecipientStatus;

/**
 * One person that signs, approves, witnesses or receives a copy of an envelope.
 *
 * @property string $envelope_id
 * @property int $signing_order
 * @property RecipientRole $role
 * @property string $full_name
 * @property string $email
 * @property string|null $phone
 * @property array<string, mixed>|null $identity_metadata
 * @property AuthMethod $auth_method
 * @property string|null $access_token_hash
 * @property string|null $access_token
 * @property Carbon|null $token_expires_at
 * @property string|null $otp_hash
 * @property Carbon|null $otp_expires_at
 * @property int $otp_attempts
 * @property array{page: int, x: float, y: float, width: float, height: float}|null $signature_box
 * @property RecipientStatus $status
 * @property string|null $external_id
 * @property string|null $prepared_path
 * @property string|null $signature_data
 * @property string|null $signature_method
 * @property string|null $certificate_serial
 * @property string|null $certificate_subject
 * @property Carbon|null $notified_at
 * @property Carbon|null $viewed_at
 * @property Carbon|null $consent_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $declined_at
 * @property string|null $decline_reason
 */
final class SignatureRecipient extends BaseEnterpriseModel
{
    protected $table = 'signature_recipients';

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token_hash', 'access_token', 'otp_hash', 'identity_metadata', 'prepared_path', 'signature_data'];

    protected function casts(): array
    {
        return [
            'role' => RecipientRole::class,
            'auth_method' => AuthMethod::class,
            'status' => RecipientStatus::class,
            'identity_metadata' => 'encrypted:array',
            'access_token' => 'encrypted',
            'signature_box' => 'array',
            'signing_order' => 'integer',
            'otp_attempts' => 'integer',
            'token_expires_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'notified_at' => 'datetime',
            'viewed_at' => 'datetime',
            'consent_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SignatureEnvelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SignatureEnvelope::class, 'envelope_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
