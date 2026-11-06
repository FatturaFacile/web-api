<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Payroll\Http\Requests\SaveItalianSettingsRequest;
use Modules\Payroll\Http\Requests\SaveSwissSettingsRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\PayrollSetupService;
use OpenApi\Attributes as OA;

/**
 * Company settings of the payroll.
 */
final class PayrollSettingsController extends PayrollController
{
    public function __construct(TenantContext $tenantContext, private readonly PayrollSetupService $setupService)
    {
        parent::__construct($tenantContext);
    }

    /**
     * Create what a Swiss company needs to start.
     */
    #[OA\Post(
        path: '/api/v1/payroll/setup',
        operationId: 'setupSwissPayroll',
        description: 'Swiss companies: create the default settings and the standard wage type catalog when they are missing. Existing records are left as they are.',
        summary: 'Set up Swiss payroll',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Whether anything was created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function setup(): JsonResponse
    {
        $this->authorize('create', SwissPayrollSettings::class);
        $this->requireCountry('CH', 'La configurazione delle paghe svizzere è disponibile per le aziende svizzere.');

        return response()->json(['data' => ['changed' => $this->setupService->ensureSwissSetup()]]);
    }

    /**
     * Show the Swiss settings.
     */
    #[OA\Get(
        path: '/api/v1/payroll/settings/swiss',
        operationId: 'showSwissPayrollSettings',
        description: 'Return the Swiss payroll settings of the company, or null when they have not been created yet.',
        summary: 'Show Swiss settings',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function showSwiss(): JsonResponse
    {
        $this->authorize('viewAny', SwissPayrollSettings::class);
        $settings = SwissPayrollSettings::query()->first();

        return response()->json(['data' => $settings === null ? null : new PayrollRecordResource($settings)]);
    }

    /**
     * Change the Swiss settings.
     */
    #[OA\Put(
        path: '/api/v1/payroll/settings/swiss',
        operationId: 'updateSwissPayrollSettings',
        description: 'Create or change the Swiss payroll settings of the company.',
        summary: 'Update Swiss settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSwissSettingsRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateSwiss(SaveSwissSettingsRequest $request): PayrollRecordResource
    {
        $this->authorize('create', SwissPayrollSettings::class);

        $settings = SwissPayrollSettings::query()->firstOrNew();
        $settings->fill($request->validated())->save();

        return $this->savedRecord($settings);
    }

    /**
     * Show the INPS and INAIL settings.
     */
    #[OA\Get(
        path: '/api/v1/payroll/settings/italian',
        operationId: 'showItalianPayrollSettings',
        description: 'Return the INPS and INAIL settings of the company, or null when they have not been created yet.',
        summary: 'Show Italian settings',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function showItalian(): JsonResponse
    {
        $this->authorize('viewAny', ItalianPayrollSettings::class);
        $settings = ItalianPayrollSettings::query()->first();

        return response()->json(['data' => $settings === null ? null : new PayrollRecordResource($settings)]);
    }

    /**
     * Change the INPS and INAIL settings.
     */
    #[OA\Put(
        path: '/api/v1/payroll/settings/italian',
        operationId: 'updateItalianPayrollSettings',
        description: 'Create or change the INPS and INAIL settings of the company.',
        summary: 'Update Italian settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollItalianSettingsRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateItalian(SaveItalianSettingsRequest $request): PayrollRecordResource
    {
        $this->authorize('create', ItalianPayrollSettings::class);

        $settings = ItalianPayrollSettings::query()->firstOrNew();
        $settings->fill($request->validated())->save();

        return $this->savedRecord($settings);
    }
}
