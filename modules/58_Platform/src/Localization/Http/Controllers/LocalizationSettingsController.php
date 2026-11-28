<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Localization\Application\Commands\UpdateLocaleSettingsCommand;
use Modules\Platform\Localization\Application\Commands\UpdateLocaleSettingsHandler;
use Modules\Platform\Localization\Application\Queries\GetLocaleSettingsHandler;
use Modules\Platform\Localization\Application\Queries\GetLocaleSettingsQuery;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;
use Modules\Platform\Localization\Http\Requests\UpdateLocaleSettingsRequest;
use Modules\Platform\Localization\Http\Resources\LocaleSettingsResource;
use OpenApi\Attributes as OA;

final class LocalizationSettingsController extends Controller
{
    use RespondsWithEnvelope;

    #[OA\Get(
        path: '/api/v1/settings/localization',
        operationId: 'getLocalizationSettings',
        description: 'The languages the company works in, its default language and the optional number and date format overrides.',
        summary: 'Get the localization settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        responses: [
            new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationSettingsResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
        ]
    )]
    public function show(Request $request, GetLocaleSettingsHandler $handler): JsonResponse
    {
        $result = $handler(new GetLocaleSettingsQuery($this->tenantId()));

        return $this->envelope((new LocaleSettingsResource($result['settings']))->resolve($request), __('localization.messages.settings'));
    }

    #[OA\Put(
        path: '/api/v1/settings/localization',
        operationId: 'updateLocalizationSettings',
        description: 'Changes the active languages, the default language and the number and date format overrides of the company. Owners and administrators only.',
        summary: 'Update the localization settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LocalizationSettingsPayload')),
        responses: [
            new OA\Response(response: 200, description: 'The stored settings', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationSettingsResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateLocaleSettingsRequest $request, UpdateLocaleSettingsHandler $handler): JsonResponse
    {
        $validated = $request->validated();
        $number = $validated['number_format'] ?? null;

        $settings = $handler(new UpdateLocaleSettingsCommand($this->tenantId(), new LocaleSettings(
            array_map(static fn (string $code): SupportedLocale => SupportedLocale::from($code), array_values($validated['active_locales'])),
            SupportedLocale::from((string) $validated['default_locale']),
            is_array($number) ? ['decimal_separator' => (string) $number['decimal_separator'], 'thousands_separator' => (string) ($number['thousands_separator'] ?? '')] : null,
            $validated['date_format'] ?? null,
        )));

        return $this->envelope((new LocaleSettingsResource($settings))->resolve($request), __('localization.messages.settings_updated'));
    }
}
