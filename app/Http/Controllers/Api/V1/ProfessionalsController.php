<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Professionals\LogBillableActivityRequest;
use App\Http\Requests\Professionals\StoreFeeNoteRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BillableActivity;
use App\Models\FeeNote;
use App\Models\LegalCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ProfessionalsController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/professionals/cases',
        operationId: 'getLegalCases',
        description: 'List professional legal matters and dossier cases (Fascicoli).',
        summary: 'List legal cases',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Professionals'],
        responses: [
            new OA\Response(response: 200, description: 'Legal cases retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cases(): JsonResponse
    {
        $cases = LegalCase::latest()->paginate(25);

        return $this->successResponse(data: $cases, message: 'Legal cases (Fascicoli) retrieved');
    }

    #[OA\Get(
        path: '/api/v1/professionals/time-logs',
        operationId: 'getTimeLogs',
        description: 'List logged billable professional time activities.',
        summary: 'List billable time logs',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Professionals'],
        responses: [
            new OA\Response(response: 200, description: 'Billable activities retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function timeLogs(Request $request): JsonResponse
    {
        $logs = BillableActivity::latest('date')->paginate(25);

        return $this->successResponse(data: $logs, message: 'Billable activities retrieved');
    }

    #[OA\Post(
        path: '/api/v1/professionals/time-logs',
        operationId: 'logBillableActivity',
        description: 'Record professional billable activity and calculated fee amount.',
        summary: 'Log billable activity',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Professionals'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['legal_case_id', 'date', 'duration_minutes', 'hourly_rate', 'description'],
                properties: [
                    new OA\Property(property: 'legal_case_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'duration_minutes', type: 'integer', example: 90),
                    new OA\Property(property: 'hourly_rate', type: 'number', format: 'float', example: 180.00),
                    new OA\Property(property: 'description', type: 'string', example: 'Contract review and drafting'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Billable time logged successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function logActivity(LogBillableActivityRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $fee = round(($validated['duration_minutes'] / 60.0) * (float) $validated['hourly_rate'], 2);

        $activity = BillableActivity::create([
            'legal_case_id' => $validated['legal_case_id'],
            'user_id' => $request->user()?->id,
            'date' => $validated['date'],
            'duration_minutes' => $validated['duration_minutes'],
            'hourly_rate' => $validated['hourly_rate'],
            'fee_amount' => $fee,
            'description' => $validated['description'],
            'is_billed' => false,
        ]);

        return $this->successResponse(
            data: ['id' => $activity->id, 'fee_amount' => $fee],
            message: 'Billable time logged successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/professionals/fee-notes',
        operationId: 'getFeeNotes',
        description: 'List professional fee notes (Parcelle pro-forma).',
        summary: 'List fee notes',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Professionals'],
        responses: [
            new OA\Response(response: 200, description: 'Professional fee notes retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function feeNotes(): JsonResponse
    {
        $notes = FeeNote::latest('date')->paginate(25);

        return $this->successResponse(data: $notes, message: 'Professional fee notes (Parcelle) retrieved');
    }

    #[OA\Post(
        path: '/api/v1/professionals/fee-notes',
        operationId: 'createFeeNote',
        description: 'Generate professional fee note calculating Cassa Previdenza (CPA) and Ritenuta d\'Acconto withholding.',
        summary: 'Create fee note (Parcella)',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Professionals'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id', 'note_number', 'date', 'taxable_fees'],
                properties: [
                    new OA\Property(property: 'legal_case_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'note_number', type: 'string', example: 'PAR-2026-001'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'taxable_fees', type: 'number', format: 'float', example: 2500.00),
                    new OA\Property(property: 'cpa_rate', type: 'number', format: 'float', example: 4.0),
                    new OA\Property(property: 'ritenuta_rate', type: 'number', format: 'float', example: 20.0),
                    new OA\Property(property: 'vat_rate', type: 'number', format: 'float', example: 22.0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Fee note created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeFeeNote(StoreFeeNoteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $taxable = (float) $validated['taxable_fees'];
        $cpaRate = (float) ($validated['cpa_rate'] ?? 4.0);
        $ritenutaRate = (float) ($validated['ritenuta_rate'] ?? 20.0);
        $vatRate = (float) ($validated['vat_rate'] ?? 22.0);

        $cpa = round($taxable * ($cpaRate / 100.0), 2);
        $imponibileIva = $taxable + $cpa;
        $vat = round($imponibileIva * ($vatRate / 100.0), 2);
        $ritenuta = round($taxable * ($ritenutaRate / 100.0), 2);
        $netPayable = ($imponibileIva + $vat) - $ritenuta;

        $feeNote = FeeNote::create([
            'legal_case_id' => $validated['legal_case_id'] ?? null,
            'client_id' => $validated['client_id'],
            'note_number' => $validated['note_number'],
            'date' => $validated['date'],
            'taxable_fees' => $taxable,
            'cassa_previdenza_amount' => $cpa,
            'ritenuta_acconto_amount' => $ritenuta,
            'vat_amount' => $vat,
            'net_payable' => $netPayable,
            'status' => 'draft',
        ]);

        return $this->successResponse(
            data: [
                'id' => $feeNote->id,
                'taxable_fees' => $taxable,
                'cassa_previdenza' => $cpa,
                'vat' => $vat,
                'ritenuta_acconto' => $ritenuta,
                'net_payable' => $netPayable,
            ],
            message: 'Fee note (Parcella) created',
            statusCode: Response::HTTP_CREATED
        );
    }
}
