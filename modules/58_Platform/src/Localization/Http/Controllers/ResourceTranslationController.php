<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Localization\Application\Commands\UpsertResourceTranslationCommand;
use Modules\Platform\Localization\Application\Commands\UpsertResourceTranslationHandler;
use Modules\Platform\Localization\Application\Queries\GetTranslatableFieldsHandler;
use Modules\Platform\Localization\Application\Queries\GetTranslatableFieldsQuery;
use Modules\Platform\Localization\Domain\Enums\TranslatableResource;
use Modules\Platform\Localization\Domain\Exceptions\ReadOnlyResourceException;
use Modules\Platform\Localization\Http\Requests\UpsertResourceTranslationRequest;
use Modules\Platform\Localization\Http\Resources\ResourceTranslationsResource;
use OpenApi\Attributes as OA;

/**
 * Translations of master data (products, categories, payment terms, shipping reasons, units, standard texts) per locale.
 */
final class ResourceTranslationController extends Controller
{
    use RespondsWithEnvelope;

    #[OA\Get(
        path: '/api/v1/{resource}/{id}/translations',
        operationId: 'getResourceTranslations',
        description: 'The translated fields of a master data record in every active language of the company, with the completeness of each language. Resources: products, product-categories, payment-terms, shipping-reasons, units-of-measure, standard-texts.',
        summary: 'Get the translations of a master data record',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'resource', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'product-categories', 'payment-terms', 'shipping-reasons', 'units-of-measure', 'standard-texts'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The translations', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationResourceTranslationsResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $resource, string $id, GetTranslatableFieldsHandler $handler): JsonResponse
    {
        $type = TranslatableResource::tryFrom($resource);
        abort_if($type === null, 404);

        return $this->envelope(
            (new ResourceTranslationsResource($handler(new GetTranslatableFieldsQuery($this->tenantId(), $type, $id))))->resolve($request),
            __('localization.messages.resource_translations'),
        );
    }

    #[OA\Put(
        path: '/api/v1/{resource}/{id}/translations',
        operationId: 'updateResourceTranslations',
        description: 'Sets the translated fields of a record for one or more locales ({translations: {de-CH: {name, description}}}); an emptied locale loses its row. Owners and administrators only; a global record shared by every company is read only.',
        summary: 'Update the translations of a master data record',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'resource', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['products', 'product-categories', 'payment-terms', 'shipping-reasons', 'units-of-measure', 'standard-texts'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['translations'], properties: [new OA\Property(property: 'translations', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'object', additionalProperties: true))])),
        responses: [
            new OA\Response(response: 200, description: 'The translations after the change', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationResourceTranslationsResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/LocalizationNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function update(UpsertResourceTranslationRequest $request, string $resource, string $id, UpsertResourceTranslationHandler $writer, GetTranslatableFieldsHandler $reader): JsonResponse
    {
        $type = $request->resource();

        try {
            $writer(new UpsertResourceTranslationCommand($this->tenantId(), $type, $id, (array) $request->validated('translations')));
        } catch (ReadOnlyResourceException $exception) {
            return $this->problem(403, __('localization.resource.read_only_title'), __('localization.resource.read_only_detail'));
        }

        return $this->envelope(
            (new ResourceTranslationsResource($reader(new GetTranslatableFieldsQuery($this->tenantId(), $type, $id))))->resolve($request),
            __('localization.messages.resource_translations_saved'),
        );
    }
}
