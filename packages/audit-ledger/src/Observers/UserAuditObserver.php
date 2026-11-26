<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Observers;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

/**
 * CRUD audit of the user accounts (Mod 004 / Mod 005). Names and e-mail addresses enter the chain as pseudonym tokens,
 * passwords, remember tokens and two factor secrets never enter it, and bookkeeping columns (last login, remember token)
 * do not make an entry on their own.
 */
final class UserAuditObserver
{
    /** @var list<string> */
    private const array NOISE = ['updated_at', 'last_login_at', 'last_login_ip', 'last_seen_at', 'remember_token', 'email_verified_at'];

    public function __construct(private readonly AuditLedgerInterface $auditLedger) {}

    public function created(User $user): void
    {
        $this->record('CREATED', $user, null, $user->getAttributes());
    }

    public function updated(User $user): void
    {
        $changes = array_diff_key($user->getChanges(), array_flip(self::NOISE));

        if ($changes === []) {
            return;
        }

        $this->record('UPDATED', $user, $user->getRawOriginal(), $changes);
    }

    public function deleted(User $user): void
    {
        $this->record('DELETED', $user, $user->getRawOriginal(), null);
    }

    public function restored(User $user): void
    {
        $this->record('RESTORED', $user, null, ['restored' => true]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, User $user, ?array $before, ?array $after): void
    {
        $this->auditLedger->recordPayload([
            'action' => $action,
            'model_class' => User::class,
            'model_id' => (string) $user->getKey(),
            'tenant_id' => (string) ($user->getAttribute('current_tenant_id') ?? app(TenantContext::class)->getTenantId() ?? '00000000-0000-0000-0000-000000000000'),
            'module_code' => 'IDENTITY',
            'category' => 'crud',
            'payload_before' => $before,
            'payload_after' => $after,
            'actor_user_id' => auth()->id(),
            'personal_attributes' => ['name', 'email', 'phone', 'first_name', 'last_name'],
            'excluded_attributes' => array_merge(self::NOISE, ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']),
        ]);
    }
}
