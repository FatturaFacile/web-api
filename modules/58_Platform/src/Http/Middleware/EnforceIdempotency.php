<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Models\IdempotencyKey;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Makes mutating requests safe to retry: a request carrying an `Idempotency-Key` header runs once per user, and a retry
 * with the same key and the same payload gets the stored response back (header `Idempotent-Replayed: true`).
 *
 * - Same key, different method, path or payload: 422.
 * - Same key while the first request is still running: 409.
 * - Server errors (5xx) and file responses are not stored, so the client can retry them.
 * Requests without the header are untouched.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $user = $request->user();

        if ($key === null || $user === null || ! in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $key = trim((string) $key);

        if ($key === '' || strlen($key) > (int) config('platform.idempotency.max_key_length', 255)) {
            return $this->problem(422, 'platform.idempotency.invalid_key');
        }

        $userId = (string) $user->getAuthIdentifier();
        $hash = $this->requestHash($request);

        $existing = IdempotencyKey::query()->where('user_id', $userId)->where('idempotency_key', $key)->first();

        if ($existing !== null && $existing->expires_at->isPast()) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            return $this->resolveExisting($existing, $request, $hash);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'user_id' => $userId,
                'idempotency_key' => $key,
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'request_hash' => $hash,
                'expires_at' => now()->addHours((int) config('platform.idempotency.ttl_hours', 24)),
            ]);
        } catch (QueryException) {
            return $this->problem(409, 'platform.idempotency.in_progress');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();

            throw $e;
        }

        $this->store($record, $response);

        return $response;
    }

    private function resolveExisting(IdempotencyKey $existing, Request $request, string $hash): Response
    {
        if ($existing->request_hash !== $hash || $existing->method !== $request->getMethod() || $existing->path !== $request->path()) {
            return $this->problem(422, 'platform.idempotency.key_reused');
        }

        if ($existing->response_status === null) {
            return $this->problem(409, 'platform.idempotency.in_progress');
        }

        $response = new Response((string) $existing->response_body, $existing->response_status, $existing->response_headers ?? []);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function store(IdempotencyKey $record, Response $response): void
    {
        $body = $response->getContent();
        $storable = ! $response instanceof BinaryFileResponse
            && ! $response instanceof StreamedResponse
            && is_string($body)
            && $response->getStatusCode() < 500
            && strlen($body) <= (int) config('platform.idempotency.max_stored_bytes', 1_048_576);

        if (! $storable) {
            $record->delete();

            return;
        }

        $headers = [];

        foreach (['Content-Type', 'Location', 'ETag'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        $record->forceFill(['response_status' => $response->getStatusCode(), 'response_headers' => $headers, 'response_body' => $body])->save();
    }

    private function requestHash(Request $request): string
    {
        $files = [];

        foreach ($request->allFiles() as $name => $file) {
            foreach ((array) $file as $single) {
                $files[$name][] = [$single->getClientOriginalName(), $single->getSize()];
            }
        }

        return hash('sha256', json_encode([$request->getMethod(), $request->path(), $request->query(), $request->post(), $request->getContent(), $files], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function problem(int $status, string $translationKey): JsonResponse
    {
        return response()->json([
            'type' => "https://httpstatuses.com/{$status}",
            'title' => __('platform.idempotency.title'),
            'status' => $status,
            'detail' => __($translationKey),
            'instance' => request()->getRequestUri(),
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
