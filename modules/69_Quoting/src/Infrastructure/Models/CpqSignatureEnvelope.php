<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Quoting\Domain\Enums\SignatureLevel;

/**
 * @property string $id
 * @property string $quote_id
 * @property SignatureLevel $level
 * @property string $provider
 * @property string $status
 * @property string $signer_name
 * @property string|null $signer_role
 * @property string|null $signer_email
 * @property string|null $signer_phone
 * @property string|null $otp_channel
 * @property string|null $otp_hash
 * @property Carbon|null $otp_expires_at
 * @property int $otp_attempts
 * @property string|null $provider_reference
 * @property string|null $document_hash
 * @property bool $consent_given
 * @property Carbon|null $signed_at
 * @property array<string, mixed>|null $verification_metadata
 */
final class CpqSignatureEnvelope extends BaseEnterpriseModel
{
    protected $table = 'cpq_signature_envelopes';

    /** @var list<string> */
    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'level' => SignatureLevel::class,
            'otp_expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'consent_given' => 'boolean',
            'otp_attempts' => 'integer',
            'verification_metadata' => 'array',
        ];
    }
}
