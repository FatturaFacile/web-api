<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Http\Requests\OpenBankingSyncRequest;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingSyncRun;
use Modules\Treasury\Services\OpenBankingService;
use Modules\Treasury\Services\OpenBankingStatementSync;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connections (consents) of the company with PSD2 / SIX bLink providers and their on-demand statement pulls.
 */
final class OpenBankingSyncController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OpenBankingService $openBanking,
        private readonly OpenBankingStatementSync $sync,
    ) {}

    #[OA\Get(
        path: '/api/v1/treasury/open-banking/status',
        operationId: 'getTreasuryOpenBankingStatus',
        description: 'The configured provider driver (refused, local, sandbox, psd2, blink) and whether it can be used.',
        summary: 'Open banking provider status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Open Banking'],
        responses: [new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function status(): JsonResponse
    {
        $gateway = $this->openBanking->gateway();

        return $this->successResponse(['driver' => $gateway->name(), 'available' => $gateway->isAvailable()]);
    }

    #[OA\Get(
        path: '/api/v1/treasury/open-banking/consents',
        operationId: 'listTreasuryOpenBankingConsents',
        description: 'Consents of the company with their latest sync run.',
        summary: 'List open banking connections',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Open Banking'],
        responses: [new OA\Response(response: 200, description: 'Consents', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function consents(): JsonResponse
    {
        $latest = OpenBankingSyncRun::query()->orderByDesc('started_at')->get()->groupBy('consent_id');

        return $this->successResponse(OpenBankingConsent::query()->orderByDesc('created_at')->get()->map(fn (OpenBankingConsent $consent): array => [
            'id' => $consent->id,
            'driver' => $consent->driver,
            'iban' => $consent->iban,
            'status' => $consent->status,
            'is_active' => $consent->isActive(),
            'expires_at' => $consent->expires_at?->toIso8601String(),
            'last_sync' => ($run = $latest->get($consent->id)?->first()) ? $this->presentRun($run) : null,
        ])->all());
    }

    #[OA\Get(
        path: '/api/v1/treasury/open-banking/sync-runs',
        operationId: 'listTreasuryOpenBankingSyncRuns',
        description: 'History of the statement pulls, newest first.',
        summary: 'List open banking sync runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Open Banking'],
        responses: [new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function runs(): JsonResponse
    {
        return $this->successResponse(OpenBankingSyncRun::query()->orderByDesc('started_at')->limit(50)->get()->map(fn (OpenBankingSyncRun $run): array => $this->presentRun($run))->all());
    }

    #[OA\Post(
        path: '/api/v1/treasury/open-banking/consents/{id}/sync',
        operationId: 'syncTreasuryOpenBankingConsent',
        description: 'Pulls the booked movements of the account from the provider and stores them as a bank statement. Movements already imported are skipped, so the call is idempotent.',
        summary: 'Sync an open banking connection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury Open Banking'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Sync run', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 409, description: 'Consent no longer active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 503, description: 'Provider unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sync(OpenBankingSyncRequest $request, string $id): JsonResponse
    {
        $consent = OpenBankingConsent::query()->findOrFail($id);

        try {
            $run = $this->sync->sync($consent, $request->validated('from'), $request->validated('to'), (string) $request->user()?->id, $request->boolean('auto_reconcile', true));
        } catch (OpenBankingUnavailableException $e) {
            return $this->problemDetailsResponse('Open banking not available', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->successResponse($this->presentRun($run), __('treasury_flows.messages.sync_completed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(OpenBankingSyncRun $run): array
    {
        return [
            'id' => $run->id,
            'consent_id' => $run->consent_id,
            'driver' => $run->driver,
            'status' => $run->status,
            'from_date' => $run->from_date->toDateString(),
            'to_date' => $run->to_date->toDateString(),
            'fetched' => (int) $run->fetched,
            'imported' => (int) $run->imported,
            'duplicates' => (int) $run->duplicates,
            'bank_statement_id' => $run->bank_statement_id,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
