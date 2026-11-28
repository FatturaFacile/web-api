<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Platform\Localization\Application\Commands\ImportTranslationsCommand;
use Modules\Platform\Localization\Application\Commands\ImportTranslationsHandler;
use Modules\Platform\Localization\Application\Commands\UpsertTranslationCommand;
use Modules\Platform\Localization\Application\Commands\UpsertTranslationHandler;
use Modules\Platform\Localization\Application\Queries\ExportTranslationsHandler;
use Modules\Platform\Localization\Application\Queries\ExportTranslationsQuery;
use Modules\Platform\Localization\Application\Queries\ListTranslationsHandler;
use Modules\Platform\Localization\Application\Queries\ListTranslationsQuery;
use Modules\Platform\Localization\Domain\Exceptions\LockedTranslationException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Http\Requests\ExportTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\ImportTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\ListTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\UpsertTranslationRequest;
use Modules\Platform\Localization\Http\Resources\ImportResultResource;
use Modules\Platform\Localization\Http\Resources\TranslationRowResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The translation editor of a company: browse the dictionary, override single texts, import and export spreadsheets.
 * Writes are tenant overrides; locked statutory keys are immutable (403).
 */
final class TranslationAdminController extends Controller
{
    use RespondsWithEnvelope;

    #[OA\Get(
        path: '/api/v1/settings/translations',
        operationId: 'listSettingsTranslations',
        description: 'The keys of the dictionary for one locale with the effective value, the origin of the value and the state flags. filter[missing]=1 lists only the keys without a text in the locale (or its language).',
        summary: 'List translations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'filter[locale]', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'de-CH')),
            new OA\Parameter(name: 'filter[group]', in: 'query', required: false, description: 'First segment of the key', schema: new OA\Schema(type: 'string', example: 'invoicing')),
            new OA\Parameter(name: 'filter[missing]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of translations', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationTranslationPageResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function index(ListTranslationsRequest $request, ListTranslationsHandler $handler): JsonResponse
    {
        $filter = (array) $request->validated('filter', []);
        $page = $handler(new ListTranslationsQuery(
            $this->tenantId(),
            Locale::from((string) $filter['locale']),
            isset($filter['group']) && $filter['group'] !== '' ? (string) $filter['group'] : null,
            filter_var($filter['missing'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($filter['search']) ? (string) $filter['search'] : null,
            (int) $request->validated('page', 1),
            (int) $request->validated('per_page', 50),
        ));

        $rows = array_map(static fn (array $row): array => (new TranslationRowResource($row))->resolve($request), $page['rows']);

        return $this->envelope($rows, __('localization.messages.translations'), [
            'total' => $page['total'],
            'current_page' => $page['current_page'],
            'per_page' => $page['per_page'],
            'last_page' => $page['last_page'],
            'missing_count' => $page['missing_count'],
            'groups' => $page['groups'],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/settings/translations/{group}/{key}',
        operationId: 'upsertSettingsTranslation',
        description: 'Creates or changes the override of a company for one text; the full key is group.key. Owners and administrators only. A locked statutory key answers 403.',
        summary: 'Upsert a translation override',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'group', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'invoicing')),
            new OA\Parameter(name: 'key', in: 'path', required: true, description: 'The key without its group (may contain dots)', schema: new OA\Schema(type: 'string', example: 'title')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['locale', 'value'], properties: [new OA\Property(property: 'locale', type: 'string', example: 'de-CH'), new OA\Property(property: 'value', type: 'string', example: 'Rechnung')])),
        responses: [
            new OA\Response(response: 200, description: 'The stored text', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationTranslationResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function update(UpsertTranslationRequest $request, UpsertTranslationHandler $handler, ListTranslationsHandler $reader): JsonResponse
    {
        $key = $request->translationKey();
        $locale = Locale::from((string) $request->validated('locale'));

        try {
            $handler(new UpsertTranslationCommand($this->tenantId(), $locale, $key, (string) $request->validated('value')));
        } catch (LockedTranslationException $exception) {
            return $this->problem(403, __('localization.locked.title'), __('localization.locked.detail', ['key' => $exception->key]), ['key' => $exception->key]);
        }

        $page = $reader(new ListTranslationsQuery($this->tenantId(), $locale, $key->group(), false, $key->value(), 1, 50));
        $row = collect($page['rows'])->firstWhere('key', $key->value());

        return $this->envelope(is_array($row) ? (new TranslationRowResource($row))->resolve($request) : null, __('localization.messages.translation_saved'));
    }

    #[OA\Post(
        path: '/api/v1/settings/translations/import',
        operationId: 'importSettingsTranslations',
        description: 'Imports a sheet (xlsx or csv, max 5 MB: key plus one column per locale) as company overrides. With dry_run=1 nothing is written and the diff is returned. A commit with row errors answers 422 and writes nothing; a locked key answers 403.',
        summary: 'Import translations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [new OA\Parameter(name: 'dry_run', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')]))),
        responses: [
            new OA\Response(response: 200, description: 'The diff preview or the applied changes', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationImportResponse')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationForbidden', response: 403),
            new OA\Response(response: 422, description: 'The sheet has row errors (the body carries the rows)', content: new OA\JsonContent(ref: '#/components/schemas/LocalizationImportResponse')),
        ]
    )]
    public function import(ImportTranslationsRequest $request, ImportTranslationsHandler $handler): JsonResponse
    {
        $file = $request->file('file');
        $dryRun = $request->isDryRun();

        try {
            $result = $handler(new ImportTranslationsCommand($this->tenantId(), (string) $file?->getRealPath(), (string) $file?->getClientOriginalExtension(), $dryRun));
        } catch (LockedTranslationException $exception) {
            return $this->problem(403, __('localization.locked.title'), __('localization.locked.detail', ['key' => $exception->key]), ['key' => $exception->key]);
        }

        $data = (new ImportResultResource($result))->resolve($request);

        if (! $dryRun && $result->hasErrors()) {
            return $this->problem(422, __('localization.import.has_errors_title'), __('localization.import.has_errors'), ['data' => $data]);
        }

        return $this->envelope($data, $dryRun ? __('localization.messages.import_preview') : __('localization.messages.import_done'));
    }

    #[OA\Get(
        path: '/api/v1/settings/translations/export',
        operationId: 'exportSettingsTranslations',
        description: 'Downloads the effective texts of the requested locales as a sheet: key, then one column per locale (xlsx by default, csv with format=csv).',
        summary: 'Export translations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Localization'],
        parameters: [
            new OA\Parameter(name: 'locales[]', in: 'query', required: true, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'), example: ['it-CH', 'de-CH'])),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv'], default: 'xlsx')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The spreadsheet', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
            new OA\Response(ref: '#/components/responses/LocalizationUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/LocalizationUnprocessable', response: 422),
        ]
    )]
    public function export(ExportTranslationsRequest $request, ExportTranslationsHandler $handler): BinaryFileResponse
    {
        $format = (string) ($request->validated('format') ?? 'xlsx');
        $locales = array_map(static fn (string $code): Locale => Locale::from($code), array_values((array) $request->validated('locales')));

        $path = $handler(new ExportTranslationsQuery($this->tenantId(), $locales, $format));
        $mime = $format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->download($path, 'translations.'.$format, ['Content-Type' => $mime])->deleteFileAfterSend(true);
    }
}
