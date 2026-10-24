<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sdi\ProcessSdiNotification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdi\SdiNotificationRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class SdiWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProcessSdiNotification $processNotification) {}

    /**
     * Receive an SDI receipt (delivery, rejection, ...) from the provider gateway.
     */
    public function __invoke(SdiNotificationRequest $request): JsonResponse
    {
        $log = $this->processNotification->execute($request->validated());

        return $this->successResponse(
            ['status' => $log->status, 'invoice_id' => $log->invoice_id],
            __('sdi.messages.notification_received')
        );
    }
}
