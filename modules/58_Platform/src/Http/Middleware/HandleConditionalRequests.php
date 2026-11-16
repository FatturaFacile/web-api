<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Services\EntityTag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optimistic locking with ETag / If-Match on the records listed in config `platform.etag_resources`.
 *
 * - GET of a record: the response carries its ETag; a matching If-None-Match gets 304.
 * - PUT, PATCH, DELETE with If-Match: the change is refused with 412 when the record changed since the client read it.
 * - After a successful change the response carries the new ETag.
 * Requests without If-Match are not blocked (the header is opt-in for clients).
 */
final class HandleConditionalRequests
{
    public function __construct(private readonly EntityTag $entityTag) {}

    public function handle(Request $request, Closure $next): Response
    {
        $modelClass = $this->modelFor($request);
        $id = $request->route('id');

        if ($modelClass === null || ! is_string($id)) {
            return $next($request);
        }

        $method = $request->getMethod();

        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true) && $request->headers->has('If-Match')) {
            $record = $this->find($modelClass, $id);

            if ($record !== null && ! $this->entityTag->matches((string) $request->header('If-Match'), $record)) {
                return $this->preconditionFailed($record);
            }
        }

        $response = $next($request);

        if ($response->isSuccessful() && in_array($method, ['GET', 'HEAD', 'PUT', 'PATCH'], true)) {
            $record = $this->find($modelClass, $id);

            if ($record !== null) {
                $tag = $this->entityTag->for($record);
                $response->headers->set('ETag', $tag);

                if (in_array($method, ['GET', 'HEAD'], true) && $this->entityTag->matches((string) $request->header('If-None-Match', ''), $record, allowWildcard: false)) {
                    return response('', Response::HTTP_NOT_MODIFIED, ['ETag' => $tag]);
                }
            }
        }

        return $response;
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelFor(Request $request): ?string
    {
        $uri = $request->route()?->uri();

        /** @var array<string, class-string<Model>> $resources */
        $resources = config('platform.etag_resources', []);

        return $uri !== null ? ($resources[$uri] ?? null) : null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function find(string $modelClass, string $id): ?Model
    {
        return $modelClass::query()->whereKey($id)->first();
    }

    private function preconditionFailed(Model $record): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/412',
            'title' => __('platform.conflict.title'),
            'status' => 412,
            'detail' => __('platform.conflict.detail'),
            'instance' => request()->getRequestUri(),
        ], 412, ['Content-Type' => 'application/problem+json', 'ETag' => $this->entityTag->for($record)]);
    }
}
