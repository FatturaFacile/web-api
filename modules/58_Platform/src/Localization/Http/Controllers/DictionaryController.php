<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\PublicTenantResolver;
use Illuminate\Http\Response;
use Modules\Platform\Localization\Application\Queries\GetDictionaryHandler;
use Modules\Platform\Localization\Application\Queries\GetDictionaryQuery;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Http\Requests\DictionaryRequest;
use Modules\Platform\Localization\Http\Resources\DictionaryResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The merged dictionary of a locale (system texts, then the overrides of the tenant) for the clients, cached by version
 * hash: a client that sends the hash it holds in If-None-Match gets 304 while nothing changed.
 */
final class DictionaryController extends Controller
{
    use RespondsWithEnvelope;

    #[OA\Get(
        path: '/api/v1/i18n/dictionary',
        operationId: 'getI18nDictionary',
        description: 'The complete dictionary of a locale for the tenant of the signed-in member (system texts only for guests). The ETag header is the version hash; If-None-Match with that hash answers 304 Not Modified.',
        summary: 'Get the i18n dictionary of a locale',
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'locale', in: 'query', required: true, description: 'it-CH, de-CH, fr-CH, it-IT, en (or the plain languages it, de, fr)', schema: new OA\Schema(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en', 'it', 'de', 'fr'])),
            new OA\Parameter(name: 'If-None-Match', in: 'header', required: false, description: 'Version hash held by the client', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Tenant-Id', in: 'header', required: false, description: 'Tenant UUID; honoured only for a signed-in member of that tenant', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The dictionary', headers: [new OA\Header(header: 'ETag', description: 'Quoted version hash', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(ref: '#/components/schemas/LocalizationDictionaryResponse')),
            new OA\Response(response: 304, description: 'The client dictionary is current'),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function show(DictionaryRequest $request, PublicTenantResolver $tenants, GetDictionaryHandler $handler): SymfonyResponse
    {
        $dictionary = $handler(new GetDictionaryQuery($tenants->resolve($request), Locale::from((string) $request->validated('locale'))));
        $headers = ['ETag' => $dictionary->etag(), 'Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, X-Tenant-Id'];

        if ($dictionary->matches($request->header('If-None-Match'))) {
            return new Response('', SymfonyResponse::HTTP_NOT_MODIFIED, $headers);
        }

        return $this->envelope((new DictionaryResource($dictionary))->resolve($request), __('localization.messages.dictionary'), ['entries' => count($dictionary->entries)])->withHeaders($headers);
    }
}
