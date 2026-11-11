<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'FatturaFacile API',
    description: 'Official REST API documentation for FatturaFacile ERP. The API is served over HTTPS only (plain HTTP is redirected or refused). Users authenticate with the Sanctum session cookie (cookieAuth): GET /sanctum/csrf-cookie, then login with credentials included and the X-XSRF-TOKEN header on every write. Bearer tokens (bearerAuth) exist only when the server enables the token mode and the client asked for it at login (token_mode=true). Integration API keys are a separate mechanism.',
    contact: new OA\Contact(
        name: 'FatturaFacile Technical Support',
        email: 'info@fatturafacile.eu',
        url: 'https://fatturafacile.eu'
    ),
    license: new OA\License(
        name: 'MIT',
        url: 'https://opensource.org/licenses/MIT'
    )
)]
#[OA\Server(
    url: 'https://api.fatturafacile.eu',
    description: 'Production Server'
)]
#[OA\Server(
    url: 'https://localhost:8000',
    description: 'Local Development Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Bearer token of the optional token mode: issued by POST /auth/login with token_mode=true, and accepted only while the server setting auth.token_mode.enabled is on (off by default). Not the way users normally authenticate: see cookieAuth.'
)]
#[OA\SecurityScheme(
    securityScheme: 'cookieAuth',
    type: 'apiKey',
    in: 'cookie',
    name: '__Host-fatturafacile_session',
    description: 'Default user authentication: the HttpOnly + Secure Sanctum session cookie set by the login (the name follows the SESSION_COOKIE setting). Writes also need the CSRF token: call GET /sanctum/csrf-cookie first and send the XSRF-TOKEN cookie value in the X-XSRF-TOKEN header. The request must come from an https origin listed in the Sanctum stateful domains.'
)]
#[OA\SecurityScheme(
    securityScheme: 'tenantHeader',
    type: 'apiKey',
    in: 'header',
    name: 'X-Tenant-Id',
    description: 'Enter your active Tenant UUID'
)]
abstract class Controller
{
    use AuthorizesRequests;
}
