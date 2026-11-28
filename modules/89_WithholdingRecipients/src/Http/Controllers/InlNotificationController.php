<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Application\Commands\GenerateInlPreventiveNotificationCommand;
use Modules\WithholdingRecipients\Application\Commands\GenerateInlPreventiveNotificationHandler;
use Modules\WithholdingRecipients\Http\Controllers\Concerns\ResolvesWithholdingContext;
use Modules\WithholdingRecipients\Http\Requests\GenerateInlNotificationRequest;
use Modules\WithholdingRecipients\Http\Requests\InlDownloadRequest;
use Modules\WithholdingRecipients\Http\Requests\MarkInlSentRequest;
use Modules\WithholdingRecipients\Http\Resources\InlNotificationResource;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Services\InlNotificationService;
use OpenApi\Attributes as OA;

/**
 * Preventive INL communication of occasional work (Art. 14 D.Lgs. 81/2008): generation, download, dispatch record.
 */
final class InlNotificationController extends Controller
{
    use ApiResponse;
    use ResolvesWithholdingContext;

    public function __construct(
        private readonly GenerateInlPreventiveNotificationHandler $generate,
        private readonly InlNotificationService $notifications,
    ) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/recipients/{id}/inl-notifications', operationId: 'withholdingRecipientsInlList', summary: 'INL communications of an occasional worker', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Communications')])]
    public function index(string $id): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);

        return $this->successResponse(InlNotificationResource::collection(RecipientProfile::query()->findOrFail($id)->inlNotifications()->orderByDesc('work_start_date')->get()));
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/recipients/{id}/inl-notifications', operationId: 'withholdingRecipientsInlGenerate', summary: 'Generate the preventive INL communication', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Communication generated')])]
    public function store(GenerateInlNotificationRequest $request, string $id): JsonResponse
    {
        $notification = $this->generate->handle(new GenerateInlPreventiveNotificationCommand($id, $request->validated(), $this->userId($request)));

        return $this->successResponse(new InlNotificationResource($notification), $this->message('inl_generated'), 201);
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/inl-notifications/{id}/mark-sent', operationId: 'withholdingRecipientsInlMarkSent', summary: 'Record that the INL communication was sent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Communication sent')])]
    public function markSent(MarkInlSentRequest $request, string $id): JsonResponse
    {
        $sentAt = $request->validated('sent_at');
        $notification = $this->notifications->markSent(
            OccasionalInlNotification::query()->findOrFail($id),
            $request->validated('protocol_reference'),
            $sentAt === null ? null : CarbonImmutable::parse((string) $sentAt),
            $this->userId($request),
        );

        return $this->successResponse(new InlNotificationResource($notification), $this->message('inl_sent'));
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/inl-notifications/{id}/cancel', operationId: 'withholdingRecipientsInlCancel', summary: 'Cancel an INL communication', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Communication cancelled')])]
    public function cancel(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', OccasionalInlNotification::class);

        return $this->successResponse(new InlNotificationResource($this->notifications->cancel(OccasionalInlNotification::query()->findOrFail($id), $this->userId($request))), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/inl-notifications/{id}/download', operationId: 'withholdingRecipientsInlDownload', summary: 'Download the INL communication as PDF, XML or JSON', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File')])]
    public function download(InlDownloadRequest $request, string $id): Response
    {
        Gate::authorize('viewAny', RecipientProfile::class);
        $notification = OccasionalInlNotification::query()->findOrFail($id);
        $format = (string) ($request->validated('format') ?? 'pdf');
        $name = 'inl-communication-'.$notification->work_start_date->format('Ymd');

        return match ($format) {
            'xml' => response((string) $notification->xml, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="'.$name.'.xml"']),
            'json' => response((string) json_encode($notification->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="'.$name.'.json"']),
            default => response($this->notifications->pdf($notification, $this->locale($request)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']),
        };
    }
}
