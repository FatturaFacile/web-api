<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\SodConflictException;
use App\Mail\TenantInvitationMail;
use App\Models\Access\TenantCustomRole;
use App\Models\Access\TenantInvitation;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-mail invitations to a company. The link of the e-mail carries a random token; only its SHA-256 is stored. A person
 * without an account chooses a name and a password when accepting; whoever already has one accepts while signed in with
 * that account.
 */
final class AccessInvitations
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly AccessPlanLimits $limits,
        private readonly AccessTeams $teams,
        private readonly AccessAudit $audit,
    ) {}

    /**
     * @return list<TenantInvitation>
     */
    public function list(Tenant $tenant): array
    {
        return TenantInvitation::query()->where('tenant_id', $tenant->id)->with('inviter')->orderByDesc('created_at')->get()->all();
    }

    /**
     * Invites an e-mail address; an address with an open invitation gets that invitation renewed with the new settings.
     *
     * @param  array{email: string, role?: string|null, custom_role_id?: string|null, modules?: array<string, string>|null, team_ids?: list<string>|null}  $data
     *
     * @throws DomainException when the address already belongs to a member
     * @throws ValidationException when the plan has no room for another person
     */
    public function invite(Tenant $tenant, User $actor, array $data): TenantInvitation
    {
        $email = Str::lower(trim($data['email']));

        $member = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($member !== null && $tenant->users()->where('users.id', $member->id)->exists()) {
            throw new DomainException(__('access.errors.already_member'));
        }

        $existing = TenantInvitation::query()->where('tenant_id', $tenant->id)->where('email', $email)->where('status', TenantInvitation::Pending)->first();

        if ($existing === null || ! $existing->isOpen()) {
            $this->limits->assertRoomForUsers($tenant);
        }

        $custom = isset($data['custom_role_id']) ? TenantCustomRole::query()->where('tenant_id', $tenant->id)->find($data['custom_role_id']) : null;
        $token = $this->newToken();

        $invitation = $existing ?? new TenantInvitation(['tenant_id' => $tenant->id, 'email' => $email]);
        $invitation->fill([
            'role' => $custom?->base_role ?? $data['role'] ?? 'member',
            'custom_role_id' => $custom?->id,
            'modules' => $data['modules'] ?? null,
            'team_ids' => $data['team_ids'] ?? null,
            'token_hash' => $this->hash($token),
            'status' => TenantInvitation::Pending,
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays((int) config('access.invitation_ttl_days')),
            'revoked_at' => null,
        ]);
        $invitation->save();

        $this->send($tenant, $invitation, $token, $actor);

        $this->audit->record('access.invitation.sent', (string) $tenant->id, TenantInvitation::class, $invitation->id, null, ['role' => $invitation->role, 'custom_role_id' => $invitation->custom_role_id]);

        return $invitation;
    }

    /**
     * A new link and a new expiry for an invitation that was not accepted or revoked.
     *
     * @throws DomainException when the invitation is closed
     */
    public function resend(Tenant $tenant, User $actor, TenantInvitation $invitation): TenantInvitation
    {
        if (in_array($invitation->status, [TenantInvitation::Accepted, TenantInvitation::Revoked], true)) {
            throw new DomainException(__('access.errors.invitation_closed'));
        }

        $token = $this->newToken();
        $invitation->forceFill([
            'token_hash' => $this->hash($token),
            'status' => TenantInvitation::Pending,
            'expires_at' => now()->addDays((int) config('access.invitation_ttl_days')),
        ])->save();

        $this->send($tenant, $invitation, $token, $actor);

        $this->audit->record('access.invitation.resent', (string) $tenant->id, TenantInvitation::class, $invitation->id);

        return $invitation;
    }

    public function revoke(Tenant $tenant, TenantInvitation $invitation): void
    {
        if ($invitation->status === TenantInvitation::Accepted) {
            throw new DomainException(__('access.errors.invitation_closed'));
        }

        if ($invitation->status !== TenantInvitation::Revoked) {
            $invitation->forceFill(['status' => TenantInvitation::Revoked, 'revoked_at' => now()])->save();

            $this->audit->record('access.invitation.revoked', (string) $tenant->id, TenantInvitation::class, $invitation->id);
        }
    }

    /**
     * What the public page of an invitation link shows; null for a token that is unknown, used, revoked or expired.
     *
     * @return array{email: string, tenant_name: string, role: string, has_account: bool, expires_at: string|null}|null
     */
    public function preview(string $token): ?array
    {
        $invitation = $this->open($token);

        if ($invitation === null) {
            return null;
        }

        return [
            'email' => $invitation->email,
            'tenant_name' => (string) Tenant::query()->whereKey($invitation->tenant_id)->value('name'),
            'role' => $invitation->role,
            'has_account' => $this->accountOf($invitation->email) !== null,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Accepts the invitation: creates the account when the address has none, gives the membership and the teams.
     *
     * @param  User|null  $session  the signed-in user, who must own the address of the invitation
     * @param  array{name?: string, password?: string}|null  $newAccount
     * @return array{email: string, tenant_name: string}
     *
     * @throws DomainException when the link is not valid, or the signed-in account is not the invited one
     * @throws SodConflictException when the role of the invitation breaks a segregation of duties rule
     */
    public function accept(string $token, ?User $session, ?array $newAccount): array
    {
        $invitation = $this->open($token) ?? throw new DomainException(__('access.errors.invitation_invalid'));
        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);
        $account = $this->accountOf($invitation->email);

        if ($account !== null && ($session === null || $session->id !== $account->id)) {
            throw new DomainException(__('access.errors.invitation_sign_in'));
        }

        return DB::transaction(function () use ($invitation, $tenant, $account, $newAccount): array {
            $user = $account ?? User::query()->create([
                'name' => (string) $newAccount['name'],
                'email' => $invitation->email,
                'password' => (string) $newAccount['password'],
                'locale' => 'it',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $customRoleId = $invitation->custom_role_id !== null
                && TenantCustomRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($invitation->custom_role_id)->exists()
                ? $invitation->custom_role_id
                : false;

            $this->memberships->save($tenant, $user, $invitation->role, $invitation->modules, $customRoleId);
            $this->teams->join($tenant, $user, (array) $invitation->team_ids);

            $invitation->forceFill(['status' => TenantInvitation::Accepted, 'accepted_at' => now()])->save();

            $this->audit->record('access.invitation.accepted', (string) $tenant->id, TenantInvitation::class, $invitation->id, null, ['user_id' => $user->id, 'role' => $invitation->role]);

            return ['email' => $invitation->email, 'tenant_name' => (string) $tenant->name];
        });
    }

    /**
     * The invitations of an e-mail address that may be accepted now.
     */
    private function open(string $token): ?TenantInvitation
    {
        $invitation = TenantInvitation::query()->withoutGlobalScopes()->where('token_hash', $this->hash($token))->first();

        return $invitation !== null && $invitation->isOpen() ? $invitation : null;
    }

    private function accountOf(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
    }

    private function send(Tenant $tenant, TenantInvitation $invitation, string $token, User $actor): void
    {
        $invitation->forceFill(['last_sent_at' => now()])->save();

        Mail::to($invitation->email)->send(new TenantInvitationMail(
            tenantName: (string) $tenant->name,
            inviterName: (string) $actor->name,
            role: Str::headline($invitation->role),
            url: rtrim((string) config('app.frontend_url'), '/').'/accept-invitation?token='.urlencode($token),
            expiresAt: (string) $invitation->expires_at?->toDateString(),
        ));
    }

    private function newToken(): string
    {
        return Str::random(48);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
