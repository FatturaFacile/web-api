<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\DTOs\AuditActor;
use App\Domain\Audit\Enums\AuditActorType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Who, where and which request: the actor of the current execution, snapshotted so the record survives the deletion of
 * the user.
 */
final class AuditActorResolver
{
    public function resolve(?string $tenantId): AuditActor
    {
        $user = auth()->user();
        $inConsole = app()->runningInConsole() && ! app()->runningUnitTests() && ! $user instanceof User;

        if (! $user instanceof User) {
            return new AuditActor(
                type: $inConsole ? AuditActorType::Cli : AuditActorType::System,
                id: null,
                email: null,
                role: null,
                ipAddress: null,
                userAgent: null,
                requestId: $this->requestId(),
                routeName: null,
            );
        }

        // A token limited to some abilities is a machine token; the full-access tokens of a login belong to a person.
        $isApiToken = request()->bearerToken() !== null && ! $user->tokenCan('*');

        return new AuditActor(
            type: $isApiToken ? AuditActorType::ApiToken : AuditActorType::User,
            id: (string) $user->getKey(),
            email: (string) $user->email,
            role: $this->roleOf($user, $tenantId),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->requestId(),
            routeName: request()->route()?->getName() ?? (request()->path() !== '/' ? request()->method().' /'.request()->path() : null),
        );
    }

    /**
     * Correlation id of the request: the one a gateway sent, otherwise a fresh one kept for the whole request.
     */
    public function requestId(): string
    {
        $request = request();
        $header = $request->headers->get('X-Request-Id');

        if (is_string($header) && Str::isUuid($header)) {
            return $header;
        }

        $existing = $request->attributes->get('audit_request_id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = (string) Str::uuid7();
        $request->attributes->set('audit_request_id', $id);

        return $id;
    }

    private function roleOf(User $user, ?string $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        $membership = $user->tenants()->where('tenants.id', $tenantId)->first();
        $role = $membership?->getRelation('pivot')->role;

        return is_string($role) ? $role : null;
    }
}
