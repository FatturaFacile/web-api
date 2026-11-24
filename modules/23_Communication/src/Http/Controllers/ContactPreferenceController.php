<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Modules\Communication\Http\Controllers\Concerns\InteractsWithCommunication;
use Modules\Communication\Http\Requests\ContactPreferenceRequest;
use Modules\Communication\Models\ContactChannelPreference;
use Modules\Communication\Services\ContactPreferenceService;
use OpenApi\Attributes as OA;

/**
 * Per-channel opt-out of a contact, shown together with the CRM consent it narrows.
 */
final class ContactPreferenceController extends Controller
{
    use InteractsWithCommunication;

    public function __construct(private readonly ContactPreferenceService $preferences) {}

    #[OA\Get(path: '/api/v1/communication/contacts/{id}/preferences', operationId: 'showCommunicationContactPreferences', description: 'For every channel: the opt-out, the effective CRM marketing consent and whether the contact can receive marketing on it (needs a CRM opt-in and no opt-out).', summary: 'Show the channel preferences of a contact', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Preferences'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Preferences per channel', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationContactPreference'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $this->authorize('viewAny', ContactChannelPreference::class);
        $contact = Contact::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return response()->json(['data' => $this->preferences->overview($this->tenantId(), (string) $contact->id)]);
    }

    #[OA\Put(path: '/api/v1/communication/contacts/{id}/preferences', operationId: 'updateCommunicationContactPreference', description: 'Opts the contact out of a channel or back in. An opt-out wins over any CRM consent; an opt-in here does not create consent, which stays in the CRM.', summary: 'Set a channel preference', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationContactPreferenceRequest')), tags: ['Communication Preferences'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Preferences per channel', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationContactPreference'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function update(ContactPreferenceRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', ContactChannelPreference::class);
        $contact = Contact::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $data = $request->validated();
        $this->preferences->set($this->tenantId(), (string) $contact->id, $data['channel'], (bool) $data['is_opted_out'], $data['reason'] ?? null, $request->user()?->id);

        return response()->json(['data' => $this->preferences->overview($this->tenantId(), (string) $contact->id)]);
    }
}
