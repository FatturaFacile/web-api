<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'FatturaFacile API',
    description: 'Official REST API documentation for FatturaFacile ERP. Publicly viewable; API requests require Bearer token authorization where indicated.',
    contact: new OA\Contact(
        name: 'FatturaFacile Technical Support',
        email: 'admin@fatturafacile.eu',
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
    url: 'http://localhost:8000',
    description: 'Local Development Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Enter your Bearer authentication token (Laravel Sanctum)'
)]
abstract class Controller
{
    //
}
