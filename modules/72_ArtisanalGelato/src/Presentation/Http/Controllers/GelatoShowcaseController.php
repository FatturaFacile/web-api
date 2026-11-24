<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\ArtisanalGelato\Application\Commands\PublishActiveShowcaseFlavorsAndIngredientBookCommand;
use Modules\ArtisanalGelato\Application\Handlers\PublishActiveShowcaseFlavorsAndIngredientBookHandler;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoShowcaseActiveFlavor;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoShowcaseIngredientBook;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\Concerns\MapsGelatoErrors;
use Modules\ArtisanalGelato\Presentation\Http\Requests\PublishGelatoShowcaseRequest;
use Modules\ArtisanalGelato\Presentation\Http\Resources\GelatoShowcaseBookResource;
use OpenApi\Attributes as OA;

/**
 * The showcase wells of a location and the published multi-language ingredient and allergen book (tablet, TV board, QR view).
 */
final class GelatoShowcaseController extends Controller
{
    use ApiResponse;
    use MapsGelatoErrors;

    #[OA\Get(path: '/api/v1/gelato/showcase/{locationKey}', operationId: 'getGelatoShowcase', summary: 'Active showcase wells', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'locationKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The wells with their flavor')])]
    public function current(string $locationKey): JsonResponse
    {
        $slots = GelatoShowcaseActiveFlavor::query()->where('location_key', $locationKey)->where('is_active', true)->orderBy('slot_number')->get()
            ->map(static fn (GelatoShowcaseActiveFlavor $slot): array => $slot->only(['slot_number', 'recipe_id']))->all();

        return $this->successResponse(['location_key' => $locationKey, 'slots' => $slots]);
    }

    #[OA\Post(path: '/api/v1/gelato/showcase/{locationKey}/publish', operationId: 'publishGelatoShowcase', summary: 'Publish the showcase and the allergen book', description: 'Sets the wells, explodes bases, pastes and variegature recursively, orders ingredients by descending weight, aggregates the 14 allergens and dietary flags and stores a versioned IT/DE/FR/EN snapshot.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'locationKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'The published book'), new OA\Response(response: 422, description: 'Invalid showcase')])]
    public function publish(string $locationKey, PublishGelatoShowcaseRequest $request, PublishActiveShowcaseFlavorsAndIngredientBookHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new GelatoShowcaseBookResource($handler->handle(new PublishActiveShowcaseFlavorsAndIngredientBookCommand(
            $locationKey, $data['slots'], $data['jurisdiction'] ?? 'IT', $this->userId(),
        ))), 'Showcase published', 201));
    }

    #[OA\Get(path: '/api/v1/gelato/showcase/{locationKey}/book', operationId: 'getGelatoAllergenBook', summary: 'Latest published allergen book', description: 'locale (it, de, fr, en) returns that language only, for the countertop tablet, the TV board and the QR page.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'locationKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The book'), new OA\Response(response: 404, description: 'Nothing published')])]
    public function book(string $locationKey, Request $request): JsonResponse
    {
        $response = $this->bookResponse($locationKey, (string) $request->query('locale', ''));

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $tenantId = (string) app(TenantContext::class)->getTenantId();
        $links = [];

        foreach (PublishActiveShowcaseFlavorsAndIngredientBookHandler::LOCALES as $locale) {
            $links[$locale] = URL::signedRoute('gelato.public.book', ['tenantId' => $tenantId, 'locationKey' => $locationKey, 'locale' => $locale]);
        }

        /** @var array<string, mixed> $body */
        $body = $response->getData(true);
        $body['data']['public_urls'] = $links;

        return $response->setData($body);
    }

    #[OA\Get(path: '/api/v1/public/gelato/{tenantId}/showcase/{locationKey}/book', operationId: 'getPublicGelatoAllergenBook', summary: 'Public QR allergen book', description: 'Customer facing book behind a signed URL (the QR code printed on the showcase): no token and no tenant header, the signature is the credential. Returns the ingredients in descending weight, the 14 allergens and the defrosted notice in the requested language.', tags: ['Artisanal Gelato'], parameters: [new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'locationKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it', 'de', 'fr', 'en'])), new OA\Parameter(name: 'signature', in: 'query', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The book'), new OA\Response(response: 403, description: 'Invalid signature'), new OA\Response(response: 404, description: 'Nothing published')])]
    public function publicBook(string $tenantId, string $locationKey, Request $request): JsonResponse
    {
        return $this->bookResponse($locationKey, (string) $request->query('locale', ''));
    }

    private function bookResponse(string $locationKey, string $locale): JsonResponse
    {
        $book = GelatoShowcaseIngredientBook::query()->where('location_key', $locationKey)->orderByDesc('version')->first();

        if ($book === null) {
            return $this->problemDetailsResponse('Not Found', 'No showcase has been published for this location.', 404);
        }

        $payload = (array) $book->payload;

        if (isset($payload[$locale])) {
            $payload = [$locale => $payload[$locale]];
        }

        return $this->successResponse(['location_key' => $book->location_key, 'jurisdiction' => $book->jurisdiction, 'version' => $book->version, 'published_at' => $book->published_at, 'books' => $payload]);
    }
}
