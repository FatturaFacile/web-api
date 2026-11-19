<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Http\Requests\Recruiting\UpsertEmailTemplateRequest;
use OpenApi\Attributes as OA;

final class RecruitingEmailTemplateController extends Controller
{
    public function __construct(
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/email-templates',
        operationId: 'hrRecruitingListEmailTemplates',
        description: 'Stage e-mail templates, optionally filtered by stage and locale.',
        summary: 'List recruiting e-mail templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'stage', in: 'query', required: false, description: 'Pipeline stage', schema: new OA\Schema(type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected'])), new OA\Parameter(name: 'locale', in: 'query', required: false, description: 'Template language', schema: new OA\Schema(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en']))],
        responses: [
            new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $stage = $request->filled('stage') ? ApplicationStage::tryFrom((string) $request->query('stage')) : null;
        $locale = $request->filled('locale') ? RecruitingLocale::tryFrom((string) $request->query('locale')) : null;

        return response()->json(['data' => $this->recruitment->emailTemplates($stage, $locale)]);
    }

    #[OA\Put(
        path: '/api/v1/hr/recruiting/email-templates/{stage}/{locale}',
        operationId: 'hrRecruitingUpsertEmailTemplate',
        description: 'Creates or replaces the e-mail template of a stage and language; an unknown stage or locale answers 404.',
        summary: 'Save recruiting e-mail template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'stage', in: 'path', required: true, description: 'Pipeline stage', schema: new OA\Schema(type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected'])), new OA\Parameter(name: 'locale', in: 'path', required: true, description: 'Template language', schema: new OA\Schema(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en']))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['subject', 'body', 'is_enabled'], properties: [new OA\Property(property: 'subject', type: 'string', maxLength: 255), new OA\Property(property: 'body', type: 'string', maxLength: 20000), new OA\Property(property: 'is_enabled', type: 'boolean')])),
        responses: [
            new OA\Response(response: 200, description: 'Template saved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function upsert(UpsertEmailTemplateRequest $request, string $stage, string $locale): JsonResponse
    {
        $stageEnum = ApplicationStage::tryFrom($stage);
        $localeEnum = RecruitingLocale::tryFrom($locale);

        if ($stageEnum === null || $localeEnum === null) {
            abort(404);
        }

        return response()->json(['data' => $this->recruitment->upsertEmailTemplate(
            $stageEnum,
            $localeEnum,
            (string) $request->input('subject'),
            (string) $request->input('body'),
            $request->boolean('is_enabled')
        )]);
    }
}
