<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maps the rule violations of the gelato services (InvalidArgumentException) to RFC 7807 problem details with status 422.
 */
trait MapsGelatoErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse('Gelato Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function userId(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? (string) $user->getKey() : null;
    }
}
