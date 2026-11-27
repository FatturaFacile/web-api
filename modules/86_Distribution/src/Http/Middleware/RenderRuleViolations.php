<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a refused stock rule of the distribution routes as an RFC 7807 problem with a stable `code` the interface translates
 * (`distribution.error.<code>`). The inventory rules render themselves as a plain 422 `{message}` (validation failures carry
 * `errors` and are left alone); the message stays in the body for API clients.
 */
final class RenderRuleViolations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 422) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body) || ! isset($body['message']) || ! is_string($body['message']) || isset($body['errors']) || isset($body['code'])) {
            return $response;
        }

        return response()->json([
            'type' => 'https://httpstatuses.com/422',
            'title' => 'Distribution rule violated',
            'status' => 422,
            'detail' => $body['message'],
            'message' => $body['message'],
            'code' => self::codeOf($body['message'], $request->route()?->getName()),
            'instance' => $request->getRequestUri(),
        ], 422, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * The stable code of a refusal, from the rule that was broken and the action that was attempted.
     */
    public static function codeOf(string $message, ?string $route): string
    {
        $route ??= '';

        return match (true) {
            str_contains($message, 'is blocked for transfers') => 'warehouse_blocked',
            str_contains($message, 'is being counted') => 'warehouse_counting',
            str_contains($message, 'is not active') => 'warehouse_inactive',
            str_contains($message, 'Insufficient available stock') && str_contains($route, 'consignment') => 'settlement_exceeds_stock',
            str_contains($message, 'Insufficient available stock') => 'insufficient_stock',
            str_contains($message, 'cannot be accounted for') => 'receipt_exceeds_shipped',
            str_contains($message, 'Invalid receipt resolution') => 'resolution_required',
            str_contains($message, 'The transfer order is') && str_contains($route, 'cancel') => 'cannot_cancel_in_transit',
            str_contains($message, 'The transfer order is') => 'invalid_status_transition',
            str_contains($message, 'proposal') || str_contains($message, 'Proposal') => 'proposal_not_pending',
            str_contains($message, 'third-party logistics') => 'not_a_3pl_warehouse',
            str_contains($message, 'consignment') => 'not_a_consignment_warehouse',
            default => 'rule_violation',
        };
    }
}
