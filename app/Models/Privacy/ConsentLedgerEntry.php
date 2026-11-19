<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use App\Enums\Privacy\ConsentProofChannel;
use App\Enums\Privacy\DsarSubjectType;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable, append-only consent ledger: every row carries the hash of the previous one, so any later edit breaks the
 * chain (GDPR art. 7(1) proof of consent). Updates and deletes are refused.
 *
 * @property string $id
 * @property string $tenant_id
 * @property DsarSubjectType $subject_type
 * @property string|null $subject_entity_id
 * @property string $subject_identifier_hash
 * @property string $purpose_code
 * @property bool $consent_granted
 * @property string $policy_version
 * @property CarbonImmutable $captured_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property ConsentProofChannel $proof_channel
 * @property int $sequence
 * @property string $entry_hash
 * @property string $previous_entry_hash
 */
final class ConsentLedgerEntry extends Model
{
    use BelongsToTenant, HasUuidV7;

    public const string GenesisHash = '0000000000000000000000000000000000000000000000000000000000000000';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_consent_ledger';

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('The consent ledger is append-only.'));
        self::deleting(static fn (): never => throw new LogicException('The consent ledger is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'subject_type' => DsarSubjectType::class,
            'proof_channel' => ConsentProofChannel::class,
            'consent_granted' => 'boolean',
            'captured_at' => 'immutable_datetime',
            'sequence' => 'integer',
        ];
    }

    /**
     * Hash of the entry content chained to the previous one.
     *
     * @param  array<string, mixed>  $content
     */
    public static function computeHash(array $content, string $previousHash): string
    {
        ksort($content);

        return hash('sha256', $previousHash.'|'.json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
