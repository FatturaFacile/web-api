<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Enums\AccessReason;
use Modules\Associations\Enums\CredentialKind;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\Member;
use Modules\Associations\Support\BlindIndex;

/**
 * QR codes and badges. A QR carries a random id and a signature, and nothing about the person: the signature stops
 * forged codes before any lookup, and the stored row is what makes the code revocable.
 */
final class CredentialService
{
    private const string Version = 'AC1';

    /**
     * Issues a new QR, replacing the current one of the person.
     */
    public function issueQr(Member $member, ?CarbonImmutable $expiresAt): Credential
    {
        return DB::transaction(function () use ($member, $expiresAt): Credential {
            $this->revokeQr($member, 'replaced');

            return Credential::create([
                'member_id' => $member->id,
                'kind' => CredentialKind::Qr,
                'public_id' => Str::random(32),
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public function registerBadge(Member $member, string $uid, ?CarbonImmutable $expiresAt): Credential
    {
        $hash = BlindIndex::badge($uid, (string) $member->tenant_id);

        if (Credential::query()->where('lookup_hash', $hash)->whereNull('revoked_at')->exists()) {
            throw AssociationException::conflict(__('associations.badge_already_registered'));
        }

        return Credential::create([
            'member_id' => $member->id,
            'kind' => CredentialKind::Badge,
            'lookup_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function revoke(Credential $credential, string $reason): Credential
    {
        $credential->update(['revoked_at' => now(), 'revoked_reason' => mb_substr($reason, 0, 255)]);

        return $credential;
    }

    public function revokeAll(Member $member, string $reason): void
    {
        $member->credentials()->whereNull('revoked_at')->get()->each(fn (Credential $credential) => $this->revoke($credential, $reason));
    }

    /**
     * The text of the QR code.
     */
    public function qrPayload(Credential $credential): string
    {
        $body = self::Version.'.'.$credential->public_id;

        return $body.'.'.$this->signature($body);
    }

    public function currentQr(Member $member): ?Credential
    {
        /** @var ?Credential */
        return $member->credentials()->where('kind', CredentialKind::Qr)->whereNull('revoked_at')->latest()->first();
    }

    /**
     * Finds what a reader of this company read. A code of another company is reported as unknown.
     *
     * @return array{credential: ?Credential, reason: ?AccessReason} the reason is set when the credential cannot be used
     */
    public function resolve(string $presented, string $tenantId): array
    {
        $parts = explode('.', $presented);

        if (count($parts) === 3 && $parts[0] === self::Version) {
            if (! hash_equals($this->signature($parts[0].'.'.$parts[1]), $parts[2])) {
                return ['credential' => null, 'reason' => AccessReason::InvalidSignature];
            }

            $credential = Credential::query()->where('public_id', $parts[1])->where('kind', CredentialKind::Qr)->first();
        } else {
            $credential = Credential::query()->where('lookup_hash', BlindIndex::badge($presented, $tenantId))->first();
        }

        if ($credential === null) {
            return ['credential' => null, 'reason' => AccessReason::UnknownCredential];
        }

        if ($credential->revoked_at !== null) {
            return ['credential' => $credential, 'reason' => AccessReason::CredentialRevoked];
        }

        if ($credential->expires_at !== null && $credential->expires_at->isPast()) {
            return ['credential' => $credential, 'reason' => AccessReason::CredentialExpired];
        }

        return ['credential' => $credential, 'reason' => null];
    }

    private function revokeQr(Member $member, string $reason): void
    {
        $member->credentials()->where('kind', CredentialKind::Qr)->whereNull('revoked_at')->get()
            ->each(fn (Credential $credential) => $this->revoke($credential, $reason));
    }

    private function signature(string $body): string
    {
        return substr(hash_hmac('sha256', $body, 'associations.credential|'.config('app.key')), 0, 32);
    }
}
