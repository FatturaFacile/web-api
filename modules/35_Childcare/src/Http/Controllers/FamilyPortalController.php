<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Childcare\Http\Requests\FamilyRequest;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\Message;
use Modules\Childcare\Models\MonthlyBill;
use Modules\Childcare\Services\CareService;
use Modules\Childcare\Services\ChildDocumentDownload;
use Modules\Childcare\Services\FamilyPortalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a guardian sees and does on the family portal: own children only, never health notes or internal notes.
 */
final class FamilyPortalController extends Controller
{
    public function __construct(private readonly FamilyPortalService $portal, private readonly CareService $care, private readonly ChildDocumentDownload $files) {}

    /**
     * Return the children of the guardian.
     */
    #[OA\Get(path: '/api/v1/childcare-family/children', operationId: 'familyChildcareChildren', description: 'Return the children of the guardian.', summary: 'My children', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], responses: [
        new OA\Response(response: 200, description: 'Children', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function children(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->childrenOf($this->contact($request))->map(static fn (Child $child): array => [
            'id' => $child->id, 'reference_code' => $child->reference_code, 'first_name' => $child->first_name, 'last_name' => $child->last_name,
        ])->values()]);
    }

    /**
     * Return the attendance of a child in a month.
     */
    #[OA\Get(path: '/api/v1/childcare-family/children/{id}/attendance', operationId: 'familyChildcareAttendance', description: 'Return the attendance of a child in a month.', summary: 'Attendance of my child', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/ChildcareId'),
        new OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11')),
    ], responses: [
        new OA\Response(response: 200, description: 'Attendance', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function attendance(FamilyRequest $request, string $id): JsonResponse
    {
        $child = $this->portal->childOf($this->contact($request), $id);
        $start = CarbonImmutable::parse($request->validated('month').'-01');

        return response()->json(['data' => Attendance::query()->where('child_id', $child->id)->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])->orderBy('date')->get()
            ->map(static fn (Attendance $attendance): array => [
                'date' => $attendance->date->toDateString(), 'status' => $attendance->status, 'absence_reason' => $attendance->absence_reason,
                'check_in_at' => $attendance->check_in_at?->format('H:i'), 'check_out_at' => $attendance->check_out_at?->format('H:i'),
            ])->values()]);
    }

    /**
     * Return the daily report of a child.
     */
    #[OA\Get(path: '/api/v1/childcare-family/children/{id}/daily-report', operationId: 'familyChildcareDailyReport', description: 'Return the daily report of a child.', summary: 'Daily report of my child', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/ChildcareId'),
        new OA\Parameter(name: 'date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Daily report', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function dailyReport(FamilyRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->care->dailyReport($this->portal->childOf($this->contact($request), $id), (string) $request->validated('date'))]);
    }

    /**
     * Return the childcare invoices addressed to the guardian.
     */
    #[OA\Get(path: '/api/v1/childcare-family/invoices', operationId: 'familyChildcareInvoices', description: 'Return the childcare invoices addressed to the guardian.', summary: 'My invoices', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], responses: [
        new OA\Response(response: 200, description: 'Invoices', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
    ])]
    public function invoices(Request $request): JsonResponse
    {
        $contact = $this->contact($request);
        $invoiceIds = MonthlyBill::query()->whereNotNull('invoice_id')->pluck('invoice_id');

        return response()->json(['data' => Invoice::query()->whereIn('id', $invoiceIds)->where('contact_id', $contact->id)->orderByDesc('date')->get()
            ->map(static fn (Invoice $invoice): array => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'date' => $invoice->date?->toDateString(), 'currency' => $invoice->currency,
                'total_gross' => (string) $invoice->total_gross, 'payment_status' => $invoice->payment_status,
            ])->values()]);
    }

    /**
     * Return the documents in the folders of the children of the guardian.
     */
    #[OA\Get(path: '/api/v1/childcare-family/documents', operationId: 'familyChildcareDocuments', description: 'Return the documents in the folders of the children of the guardian.', summary: 'My documents', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], responses: [
        new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
    ])]
    public function documents(Request $request): JsonResponse
    {
        $childIds = $this->portal->childrenOf($this->contact($request))->pluck('id');

        return response()->json(['data' => ChildDocument::query()->whereIn('child_id', $childIds)->latest()->get()
            ->map(static fn (ChildDocument $document): array => [
                'id' => $document->id, 'child_id' => $document->child_id, 'title' => $document->title, 'status' => $document->status,
                'has_file' => $document->file_path !== null, 'signed_at' => $document->signed_at?->toIso8601String(),
            ])->values()]);
    }

    /**
     * Download a document of a child of the guardian.
     */
    #[OA\Get(path: '/api/v1/childcare-family/documents/{id}/download', operationId: 'familyChildcareDocumentDownload', description: 'Download a document of a child of the guardian.', summary: 'Download my document', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'File', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function download(Request $request, string $id): StreamedResponse
    {
        $document = ChildDocument::query()->findOrFail($id);
        $this->portal->childOf($this->contact($request), $document->child_id);

        return $this->files->respond($document);
    }

    /**
     * Sign a document of a child: the typed name, the time and the guardian are recorded.
     */
    #[OA\Post(path: '/api/v1/childcare-family/documents/{id}/sign', operationId: 'familyChildcareDocumentSign', description: 'Sign a document of a child: the typed name, the time and the guardian are recorded.', summary: 'Sign document', security: [['familyToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareSignRequest')), tags: ['Childcare Family Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Signed', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function sign(FamilyRequest $request, string $id): JsonResponse
    {
        $document = $this->portal->sign($this->contact($request), ChildDocument::query()->findOrFail($id), (string) $request->validated('signed_name'));

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status, 'signed_at' => $document->signed_at?->toIso8601String()]]);
    }

    /**
     * Return the messages about the children of the guardian; messages from the kindergarten become read.
     */
    #[OA\Get(path: '/api/v1/childcare-family/messages', operationId: 'familyChildcareMessages', description: 'Return the messages about the children of the guardian; messages from the kindergarten become read.', summary: 'My messages', security: [['familyToken' => []]], tags: ['Childcare Family Portal'], responses: [
        new OA\Response(response: 200, description: 'Messages', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
    ])]
    public function messages(Request $request): JsonResponse
    {
        $childIds = $this->portal->childrenOf($this->contact($request))->pluck('id');
        $messages = Message::query()->whereIn('child_id', $childIds)->orderBy('created_at')->get();
        Message::query()->whereIn('child_id', $childIds)->where('direction', 'to_family')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => $messages->map(static fn (Message $message): array => [
            'id' => $message->id, 'child_id' => $message->child_id, 'direction' => $message->direction, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String(),
        ])->values()]);
    }

    /**
     * Send a message to the kindergarten about a child.
     */
    #[OA\Post(path: '/api/v1/childcare-family/messages', operationId: 'familyChildcareMessageStore', description: 'Send a message to the kindergarten about a child.', summary: 'Message the kindergarten', security: [['familyToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareFamilyMessageRequest')), tags: ['Childcare Family Portal'], responses: [
        new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeMessage(FamilyRequest $request): JsonResponse
    {
        $contact = $this->contact($request);
        $child = $this->portal->childOf($contact, (string) $request->validated('child_id'));
        $message = Message::query()->create(['child_id' => $child->id, 'direction' => 'from_family', 'body' => $request->validated('body'), 'sender_contact_id' => $contact->id]);

        return response()->json(['data' => ['id' => $message->id]], Response::HTTP_CREATED);
    }

    private function contact(Request $request): Contact
    {
        return $request->attributes->get('familyContact');
    }
}
