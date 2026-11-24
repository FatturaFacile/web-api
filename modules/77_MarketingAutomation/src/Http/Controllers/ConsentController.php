<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\MarketingAutomation\Http\Controllers\Concerns\ResolvesMarketingContext;
use Modules\MarketingAutomation\Http\Requests\UpdateConsentRequest;
use Modules\MarketingAutomation\Http\Resources\ConsentEventResource;
use Modules\MarketingAutomation\Http\Resources\ConsentResource;
use Modules\MarketingAutomation\Models\ConsentEvent;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Services\ConsentLedgerService;
use OpenApi\Attributes as OA;

/**
 * Granular consent state per contact and the immutable audit trail of its changes.
 */
final class ConsentController extends Controller
{
    use ApiResponse;
    use ResolvesMarketingContext;

    #[OA\Get(path: '/api/v1/marketing-automation/consents', operationId: 'marketingListConsents', summary: 'List consent states', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Consents')])]
    public function index(Request $request): JsonResponse
    {
        $consents = MarketingConsent::query()->where('tenant_id', $this->tenantId())
            ->when($request->boolean('unsubscribed'), static fn ($q) => $q->whereNotNull('unsubscribed_at'))
            ->orderByDesc('updated_at')->limit(500)->get();

        return $this->successResponse(ConsentResource::collection($consents));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/consents/{contactId}', operationId: 'marketingShowConsent', summary: 'Consent state of a contact', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Consent')])]
    public function show(string $contactId, ConsentLedgerService $ledger): JsonResponse
    {
        $consent = $ledger->current($this->tenantId(), $contactId);

        return $this->successResponse($consent === null ? null : new ConsentResource($consent));
    }

    #[OA\Put(path: '/api/v1/marketing-automation/consents/{contactId}', operationId: 'marketingUpdateConsent', summary: 'Record a consent change (audited with IP and user agent)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Consent updated')])]
    public function update(UpdateConsentRequest $request, string $contactId, ConsentLedgerService $ledger): JsonResponse
    {
        $consent = $ledger->record(
            $this->tenantId(),
            $contactId,
            $request->safe()->except('source'),
            [
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'source' => (string) ($request->validated('source') ?? 'backoffice'),
                'jurisdiction' => $this->jurisdictionOf($contactId),
            ],
            $this->userId($request),
        );

        return $this->successResponse(new ConsentResource($consent));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/consent-events', operationId: 'marketingConsentEvents', summary: 'Immutable consent audit log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Consent events')])]
    public function events(Request $request): JsonResponse
    {
        $contactId = $request->query('contact_id');

        $events = ConsentEvent::query()->where('tenant_id', $this->tenantId())
            ->when(is_string($contactId) && $contactId !== '', static fn ($q) => $q->where('contact_id', $contactId))
            ->orderByDesc('occurred_at')->limit(300)->get();

        return $this->successResponse(ConsentEventResource::collection($events));
    }

    private function jurisdictionOf(string $contactId): string
    {
        // 404 for a contact of another tenant (Contact is tenant scoped).
        return (string) Contact::query()->findOrFail($contactId)->country;
    }
}
