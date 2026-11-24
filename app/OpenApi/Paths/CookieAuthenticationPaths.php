<?php

declare(strict_types=1);

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * The first step of the cookie authentication flow, which has no controller of its own (Sanctum registers the route).
 * The whole flow: GET /sanctum/csrf-cookie, then POST /api/v1/auth/login with credentials included and the X-XSRF-TOKEN
 * header; the response sets the HttpOnly + Secure session cookie. Bearer tokens exist only in the optional token mode.
 */
#[OA\Get(
    path: '/sanctum/csrf-cookie',
    operationId: 'getCsrfCookie',
    description: 'Step 1 of the cookie login. Sets the `XSRF-TOKEN` cookie (readable by the SPA, Secure, SameSite=Lax) and the session cookie. Send the value of the `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header of every write request (login included), with credentials included. The request must carry an Origin or Referer of an https origin listed in the Sanctum stateful domains, otherwise no session is started. A write without a valid token is a 419.',
    summary: 'Get the CSRF cookie',
    tags: ['Authentication'],
    responses: [
        new OA\Response(response: 204, description: 'The XSRF-TOKEN and session cookies are set'),
    ]
)]
final class CookieAuthenticationPaths {}
