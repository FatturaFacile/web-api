<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FiscalRetail\Actions\CloseCorrispettiviDay;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Services\CorrispettiviAggregator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class CorrispettiviController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/fiscal-retail/corrispettivi/daily/{date}',
        operationId: 'getDailyCorrispettivi',
        description: 'Totals of a day by VAT rate. Open days are computed from the documents; closed days return the frozen rows.',
        summary: 'Daily corrispettivi',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [new OA\Parameter(name: 'date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Totals of the day', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid date', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function daily(Request $request, string $date, CorrispettiviAggregator $aggregator): JsonResponse
    {
        $day = $this->parsedDate($request, $date);
        $closed = DailyCorrispettivo::where('business_date', $day->toDateString())->orderBy('tax_rate')->get();

        return $this->successResponse([
            'business_date' => $day->toDateString(),
            'closed' => $closed->isNotEmpty(),
            'rows' => $closed->isNotEmpty() ? $closed : $aggregator->between($day, $day),
        ], 'Daily corrispettivi retrieved');
    }

    #[OA\Post(
        path: '/api/v1/fiscal-retail/corrispettivi/daily/{date}/close',
        operationId: 'closeDailyCorrispettivi',
        description: 'Freezes the totals of a day and hands them to the configured driver. With the local driver nothing is transmitted and the status is `simulated`.',
        summary: 'Close a corrispettivi day',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [new OA\Parameter(name: 'date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 201, description: 'Day closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Already closed, empty, in the future, or refused by the driver', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function close(Request $request, string $date, CloseCorrispettiviDay $close): JsonResponse
    {
        return $this->successResponse($close->execute($this->parsedDate($request, $date)), 'Corrispettivi day closed', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/fiscal-retail/corrispettivi/register',
        operationId: 'getCorrispettiviRegister',
        description: 'Register of the corrispettivi of a month, one row per day and VAT rate, with the totals of the month.',
        summary: 'Corrispettivi register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Fiscal Retail'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'integer', maximum: 12, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Register of the month', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function register(Request $request, CorrispettiviAggregator $aggregator): JsonResponse
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12']]);
        $from = CarbonImmutable::create((int) $validated['year'], (int) $validated['month'], 1);
        $rows = $aggregator->between($from, $from->endOfMonth());
        $sum = static fn (string $field): string => array_reduce($rows, static fn (string $total, array $row): string => bcadd($total, $row[$field], 2), '0.00');

        return $this->successResponse([
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
            'rows' => $rows,
            'totals' => ['total_net' => $sum('total_net'), 'total_tax' => $sum('total_tax'), 'total_gross' => $sum('total_gross')],
        ], 'Corrispettivi register retrieved');
    }

    private function parsedDate(Request $request, string $date): CarbonImmutable
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return CarbonImmutable::parse($date)->startOfDay();
    }
}
