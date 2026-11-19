<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Domain\Audit\DTOs\AuditActor;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Audit\Enums\AuditEventType;
use App\Models\User;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditTrailRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * LOGIN_SUCCESS and LOGIN_FAILED records of the immutable audit trail. A failed attempt on an unknown e-mail belongs to
 * no tenant: it goes to the system level chain with the attempted e-mail as context.
 */
final class RecordAuthenticationAudit
{
    public function __construct(
        private readonly AuditTrailRecorder $recorder,
        private readonly AuditActorResolver $actors,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! config('audit.enabled', true) || ! $event->user instanceof User) {
            return;
        }

        $tenantId = $event->user->tenant_id === null ? null : (string) $event->user->tenant_id;

        $this->recorder->record(
            event: AuditEventType::LoginSuccess,
            entityType: 'User',
            entityId: (string) $event->user->getKey(),
            tenantId: $tenantId,
            context: ['guard' => $event->guard],
            actor: $this->actorOf($event->user, $tenantId),
        );
    }

    public function handleFailed(Failed $event): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $user = $event->user instanceof User ? $event->user : null;
        $tenantId = $user?->tenant_id === null ? null : (string) $user->tenant_id;
        $attempted = $event->credentials['email'] ?? null;

        $this->recorder->record(
            event: AuditEventType::LoginFailed,
            entityType: 'User',
            entityId: $user === null ? null : (string) $user->getKey(),
            tenantId: $tenantId,
            context: array_filter([
                'guard' => $event->guard,
                'attempted_email' => is_string($attempted) ? $attempted : null,
                'second_factor' => isset($event->credentials['two_factor']) ? true : null,
            ]),
            actor: $user === null ? $this->anonymous() : $this->actorOf($user, $tenantId),
        );
    }

    private function actorOf(User $user, ?string $tenantId): AuditActor
    {
        $base = $this->actors->resolve($tenantId);

        return new AuditActor(AuditActorType::User, (string) $user->getKey(), (string) $user->email, $base->role, request()->ip(), request()->userAgent(), $base->requestId, $base->routeName);
    }

    private function anonymous(): AuditActor
    {
        $base = $this->actors->resolve(null);

        return new AuditActor(AuditActorType::System, null, null, null, request()->ip(), request()->userAgent(), $base->requestId, $base->routeName);
    }
}
