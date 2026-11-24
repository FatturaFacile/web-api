<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Http\Requests\Recruiting\UpdateRecruitingSettingsRequest;
use Modules\HumanResources\Http\Resources\RecruitingSettingsResource;
use OpenApi\Attributes as OA;

final class RecruitingSettingsController extends Controller
{
    public function __construct(
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/settings',
        operationId: 'hrRecruitingGetSettings',
        description: 'Careers page, retention and pipeline configuration of the company.',
        summary: 'Get recruiting settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        responses: [
            new OA\Response(response: 200, description: 'Recruiting settings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $settings = $this->recruitment->settings();

        return response()->json([
            'data' => (new RecruitingSettingsResource($settings, $this->recruitment->companyName($settings)))->resolve($request),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/hr/recruiting/settings',
        operationId: 'hrRecruitingUpdateSettings',
        description: 'Partial update of the careers page, candidate retention and pipeline stage configuration.',
        summary: 'Update recruiting settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'careers', type: 'object', properties: [new OA\Property(property: 'enabled', type: 'boolean'), new OA\Property(property: 'slug', type: 'string', minLength: 3, maxLength: 60, nullable: true, description: 'Lowercase letters, digits and hyphens; unique across companies'), new OA\Property(property: 'company_name', type: 'string', nullable: true), new OA\Property(property: 'intro', type: 'object', nullable: true, description: 'Texts keyed by locale'), new OA\Property(property: 'privacy_notice', type: 'object', nullable: true, description: 'Texts keyed by locale'), new OA\Property(property: 'privacy_policy_url', type: 'string', format: 'uri', nullable: true), new OA\Property(property: 'consent_text_version', type: 'string')]), new OA\Property(property: 'retention', type: 'object', properties: [new OA\Property(property: 'default_months', type: 'integer', description: 'Allowed values from the recruiting configuration (6 or 12)')]), new OA\Property(property: 'pipeline', type: 'object', properties: [new OA\Property(property: 'stages', type: 'array', items: new OA\Items(type: 'object', required: ['stage'], properties: [new OA\Property(property: 'stage', type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected']), new OA\Property(property: 'enabled', type: 'boolean'), new OA\Property(property: 'auto_email', type: 'boolean'), new OA\Property(property: 'labels', type: 'object', nullable: true, description: 'Labels keyed by locale')]))])])),
        responses: [
            new OA\Response(response: 200, description: 'Updated settings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateRecruitingSettingsRequest $request): JsonResponse
    {
        /** @var array{careers?: array<string, mixed>, retention?: array{default_months?: int}, pipeline?: array{stages?: list<array<string, mixed>>}} $data */
        $data = $request->validated();
        $settings = $this->recruitment->updateSettings($data);

        return response()->json([
            'data' => (new RecruitingSettingsResource($settings, $this->recruitment->companyName($settings)))->resolve($request),
        ]);
    }
}
