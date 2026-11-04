<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Listeners;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

final class AuthAuditListener
{
    public function __construct(
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $tenantId = (string) ($event->user->current_tenant_id ?? $event->user->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');
            $this->auditLedger->recordPayload([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'module_code' => 'AUTH',
                'action' => 'LOGIN_SUCCESS',
                'model_class' => User::class,
                'model_id' => $event->user->id,
                'actor_user_id' => $event->user->id,
                'payload_after' => [
                    'email' => $event->user->email,
                    'guard' => $event->guard,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $tenantId = (string) ($event->user->current_tenant_id ?? $event->user->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');
            $this->auditLedger->recordPayload([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'module_code' => 'AUTH',
                'action' => 'LOGOUT',
                'model_class' => User::class,
                'model_id' => $event->user->id,
                'actor_user_id' => $event->user->id,
                'payload_after' => [
                    'email' => $event->user->email,
                    'guard' => $event->guard,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? 'unknown';
        $user = $event->user ?? User::where('email', $email)->first();
        $tenantId = (string) ($user?->current_tenant_id ?? $user?->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');

        $this->auditLedger->recordPayload([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'module_code' => 'AUTH',
            'action' => 'LOGIN_FAILED',
            'model_class' => User::class,
            'model_id' => $user?->id ?? (string) Str::uuid7(),
            'actor_user_id' => $user?->id,
            'payload_after' => [
                'email' => $email,
                'guard' => $event->guard,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
