<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an inbound-email webhook only when `X-Signature` carries `sha256=` plus the HMAC-SHA256 of the raw body with the
 * shared secret of the mail gateway. Without a configured secret the endpoint is closed.
 */
final class VerifyInboundEmailSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('ocr_extraction.inbound_email_secret');

        if ($secret === '') {
            return response()->json(['message' => 'Inbound e-mail is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
