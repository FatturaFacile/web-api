<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\AuditEventType;
use App\Services\Audit\AuditTrailRecorder;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a VIEWED or EXPORTED record of the immutable audit trail when a sensitive record is read or exported.
 *
 * Usage: `audit.access:Invoice,VIEWED` or `audit.access:{type},EXPORTED,pdf` (a `{name}` entity is read from the route).
 * A VIEWED record for the same actor and record inside the debounce window (60 seconds) is not written again, so the
 * re-renders of a single page do not flood the trail, while a different user is logged at once.
 */
final class AuditSensitiveAccess
{
    public function __construct(
        private readonly AuditTrailRecorder $recorder,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $entity, string $action = 'VIEWED', ?string $format = null, string $idParameter = 'id'): Response
    {
        $response = $next($request);

        if (! config('audit.enabled', true) || ! $response->isSuccessful()) {
            return $response;
        }

        $event = AuditEventType::tryFrom(strtoupper($action)) ?? AuditEventType::Viewed;
        $entityName = $this->resolve($request, $entity) ?? $entity;

        // An entity read from the route ("invoice", "credit-notes") is stored under the entity name of the trail ("Invoice", "CreditNote").
        if (str_starts_with($entity, '{')) {
            $entityName = Str::studly(Str::singular($entityName));
        }
        $entityId = $this->resolve($request, '{'.$idParameter.'}');
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        $actorId = $request->user()?->getAuthIdentifier();

        if ($event === AuditEventType::Viewed && $actorId !== null) {
            $window = (int) config('audit.view_debounce_seconds', 60);
            $key = 'audit:view:'.sha1(implode('|', [$tenantId, $actorId, $entityName, $entityId ?? 'collection']));

            if ($window > 0 && ! Cache::add($key, true, $window)) {
                return $response;
            }
        }

        $context = array_filter([
            'method' => $request->method(),
            'query' => $this->query($request),
            'format' => $format ?? $request->query('format'),
            'status' => $response->getStatusCode(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $this->recorder->record(
            event: $event,
            entityType: $entityName,
            entityId: $entityId,
            tenantId: $tenantId === null ? null : (string) $tenantId,
            context: $context,
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(Request $request): array
    {
        return collect($request->query())
            ->except(['token', 'api_key', 'password', 'access_token'])
            ->all();
    }

    private function resolve(Request $request, string $template): ?string
    {
        if (! preg_match('/^\{(\w+)\}$/', $template, $matches)) {
            return $template;
        }

        $value = $request->route($matches[1]);

        if (is_object($value) && method_exists($value, 'getKey')) {
            $value = $value->getKey();
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
