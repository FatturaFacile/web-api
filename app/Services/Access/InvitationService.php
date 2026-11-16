<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Exceptions\SodConflictException;
use App\Mail\TenantInvitationMail;
use App\Models\Access\TenantInvitation;
use App\Models\Access\TenantRole;
use App\Models\Access\TenantTeam;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email invitations: an administrator invites an address with a role, the invited person sets a password (or signs in,
 * when the address already has an account) and becomes a member with that role.
 */
final class InvitationService
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly PlanLimits $limits,
        private readonly TeamService $teams,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function describeAll(Tenant $tenant): array
    {
        return TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('inviter:id,name')
            ->latest()->limit(300)->get()
            ->map(fn (TenantInvitation $invitation): array => $this->describe($invitation))
            ->all();
    }

    /**
     * @param  array<string, string>|null  $modules
     * @param  list<string>|null  $teamIds
     *
     * @throws DomainException when the address already belongs to the tenant
     */
    public function invite(Tenant $tenant, string $email, ?string $role, ?string $customRoleId, ?array $modules, ?array $teamIds, ?User $inviter): TenantInvitation
    {
        $email = Str::lower(trim($email));

        if ($tenant->users()->whereRaw('lower(users.email) = ?', [$email])->exists()) {
            throw new DomainException('This user already belongs to the tenant.');
        }

        $customRole = $customRoleId === null ? null : TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($customRoleId);

        return DB::transaction(function () use ($tenant, $email, $role, $customRole, $modules, $teamIds, $inviter): TenantInvitation {
            // A new invitation for the same address replaces the pending one.
            TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $this->limits->assertCanAddUsers($tenant);

            $token = Str::random(48);

            $invitation = TenantInvitation::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $customRole?->base_role ?? $role ?? 'member',
                'custom_role_id' => $customRole?->id,
                'modules' => $modules,
                'team_ids' => $teamIds,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter?->id,
                'expires_at' => now()->addDays((int) config('access_plans.invitation_ttl_days', 7)),
            ]);

            $this->send($invitation, $tenant, $token, $inviter);

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeInvitation, (string) $invitation->id, 'invitation.created', null, [
                'email' => $email, 'role' => $invitation->role, 'custom_role_id' => $invitation->custom_role_id,
            ]);

            return $invitation;
        });
    }

    /**
     * Issues a new link and a new expiry for a pending invitation.
     */
    public function resend(Tenant $tenant, TenantInvitation $invitation, ?User $actor): TenantInvitation
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new DomainException('Only a pending invitation can be sent again.');
        }

        $token = Str::random(48);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('access_plans.invitation_ttl_days', 7)),
        ])->save();

        $this->send($invitation, $tenant, $token, $actor);

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeInvitation, (string) $invitation->id, 'invitation.resent', null, ['email' => $invitation->email]);

        return $invitation;
    }

    public function revoke(Tenant $tenant, TenantInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeInvitation, (string) $invitation->id, 'invitation.revoked', null, ['email' => $invitation->email]);
    }

    /**
     * What the invited person sees before accepting. Null when the link is unknown, expired, used or revoked.
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $token): ?array
    {
        $invitation = $this->findPending($token);

        if ($invitation === null) {
            return null;
        }

        return [
            'email' => $invitation->email,
            'tenant_name' => $invitation->tenant?->name,
            'role' => $invitation->role,
            'has_account' => User::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    /**
     * Accepts the invitation. A new address creates the account with the given name and password; an address that
     * already has an account must be the signed-in user.
     *
     * @throws DomainException when the link is not valid, or the account exists and is not the signed-in one
     * @throws SodConflictException when the role breaks a segregation of duties rule
     */
    public function accept(string $token, ?User $signedIn, ?string $name, ?string $password): User
    {
        $invitation = $this->findPending($token);

        if ($invitation === null) {
            throw new DomainException('The invitation is not valid any more.');
        }

        $tenant = $invitation->tenant ?? throw new DomainException('The invitation is not valid any more.');
        $existing = User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();

        if ($existing !== null && ! $existing->is($signedIn)) {
            throw new DomainException('This address already has an account: sign in with it to accept the invitation.');
        }

        return DB::transaction(function () use ($invitation, $tenant, $existing, $name, $password): User {
            $user = $existing ?? $this->createUser($invitation->email, (string) $name, (string) $password);

            $this->memberships->save($tenant, $user, $invitation->custom_role_id === null ? $invitation->role : null, $invitation->modules, $invitation->custom_role_id);

            foreach ((array) $invitation->team_ids as $teamId) {
                $this->addToTeam($tenant, $user, (string) $teamId);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            AccessAudit::record((string) $tenant->id, AccessAudit::TypeInvitation, (string) $invitation->id, 'invitation.accepted', null, ['email' => $user->email, 'user_id' => $user->id]);

            return $user;
        });
    }

    private function addToTeam(Tenant $tenant, User $user, string $teamId): void
    {
        $team = TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($teamId);

        if ($team !== null) {
            $this->teams->syncMembers($tenant, $team, [...$this->teams->memberIds($team), (string) $user->id]);
        }
    }

    private function createUser(string $email, string $name, string $password): User
    {
        $user = new User(['name' => $name !== '' ? $name : Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => Carbon::now()])->save();

        return $user;
    }

    private function findPending(string $token): ?TenantInvitation
    {
        $invitation = TenantInvitation::query()->withoutGlobalScopes()->with('tenant')->where('token_hash', hash('sha256', $token))->first();

        return $invitation !== null && $invitation->isPending() ? $invitation : null;
    }

    private function send(TenantInvitation $invitation, Tenant $tenant, string $token, ?User $inviter): void
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/accept-invitation?token='.urlencode($token);

        Mail::to($invitation->email)->send(new TenantInvitationMail((string) $tenant->name, $inviter?->name, $url, $invitation->expires_at));

        $invitation->forceFill(['last_sent_at' => now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(TenantInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'custom_role_id' => $invitation->custom_role_id,
            'modules' => $invitation->modules,
            'team_ids' => $invitation->team_ids,
            'status' => $invitation->status(),
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
