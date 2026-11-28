<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Platform\Localization\Application\Commands\UpdateUserLocaleCommand;
use Modules\Platform\Localization\Application\Commands\UpdateUserLocaleHandler;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Http\Requests\UpdateUserLocaleRequest;
use OpenApi\Attributes as OA;

final class ProfileLocaleController extends Controller
{
    use RespondsWithEnvelope;

    #[OA\Put(
        path: '/api/v1/profile/locale',
        operationId: 'updateProfileLocale',
        description: 'Stores the interface language of the signed-in user. It is the first source of the locale resolution chain.',
        summary: 'Update the locale of the signed-in user',
        security: [['bearerAuth' => []]],
        tags: ['Localization'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['locale'], properties: [new OA\Property(property: 'locale', type: 'string', example: 'fr-CH')])),
        responses: [
            new OA\Response(response: 200, description: 'The stored locale', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationProfileLocaleResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateUserLocaleRequest $request, UpdateUserLocaleHandler $handler): JsonResponse
    {
        $locale = $handler(new UpdateUserLocaleCommand((string) $request->user()?->getAuthIdentifier(), Locale::from((string) $request->validated('locale'))));

        return $this->envelope(['locale' => $locale], __('localization.messages.locale_updated'));
    }
}
