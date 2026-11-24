<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Associations\DTOs\AreaAccess;
use Modules\Associations\Enums\AuthorizationStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;

/**
 * Minors: their guardians and the authorizations these accept.
 */
final class GuardianService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Links a guardian, an existing contact or a new one, to a minor.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(Member $minor, array $data): Guardianship
    {
        if (! $minor->isMinor()) {
            throw new AssociationException(__('associations.guardian_needs_minor'));
        }

        return DB::transaction(function () use ($minor, $data): Guardianship {
            $contact = isset($data['contact_id'])
                ? Contact::query()->findOrFail($data['contact_id'])
                : Contact::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'country' => $this->tenantContext->getTenant()?->country ?: 'IT',
                ]);

            if ($contact->id === $minor->contact_id) {
                throw new AssociationException(__('associations.guardian_is_minor'));
            }

            if ($minor->guardianships()->where('guardian_contact_id', $contact->id)->exists()) {
                throw AssociationException::conflict(__('associations.guardian_already_linked'));
            }

            $isPrimary = (bool) ($data['is_primary'] ?? ! $minor->guardianships()->exists());

            return Guardianship::create([
                'minor_member_id' => $minor->id,
                'guardian_contact_id' => $contact->id,
                'relationship' => $data['relationship'] ?? 'parent',
                'is_primary' => $isPrimary,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
            ])->load('guardian');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestAuthorization(Member $minor, array $data): Authorization
    {
        return Authorization::create([
            'minor_member_id' => $minor->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'body_hash' => hash('sha256', (string) $data['body']),
            'status' => AuthorizationStatus::Pending,
            'valid_until' => $data['valid_until'] ?? null,
        ]);
    }

    /**
     * Records the acceptance by a guardian of the minor, with the evidence of who did it, from where and on which text.
     */
    public function sign(Authorization $authorization, AreaAccess $access, string $signedName, Request $request): Authorization
    {
        return DB::transaction(function () use ($authorization, $access, $signedName, $request): Authorization {
            /** @var Authorization $locked */
            $locked = Authorization::query()->whereKey($authorization->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AuthorizationStatus::Pending) {
                throw AssociationException::conflict(__('associations.authorization_not_pending'));
            }

            $guardianship = Guardianship::query()->active()
                ->where('minor_member_id', $locked->minor_member_id)
                ->where('guardian_contact_id', $access->contact->id)
                ->firstOrFail();

            $locked->update([
                'status' => AuthorizationStatus::Signed,
                'guardianship_id' => $guardianship->id,
                'signed_at' => now(),
                'signed_name' => $signedName,
                'signed_ip' => $request->ip(),
                'signed_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'signed_with_token_id' => $access->token->id,
            ]);

            return $locked;
        });
    }

    public function revoke(Authorization $authorization): Authorization
    {
        $authorization->update(['status' => AuthorizationStatus::Revoked, 'revoked_at' => now()]);

        return $authorization;
    }

    /**
     * Who pays for a person: the primary guardian of a minor, the person otherwise.
     */
    public function payerContactId(Member $member): string
    {
        if ($member->isMinor()) {
            $guardianship = $member->guardianships()->active()->orderByDesc('is_primary')->first();

            if ($guardianship !== null) {
                return (string) $guardianship->guardian_contact_id;
            }
        }

        return (string) $member->contact_id;
    }
}
