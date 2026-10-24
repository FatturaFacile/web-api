<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ecommerce;

use App\Actions\Ecommerce\ProcessEcommerceWebhook;
use App\Exceptions\EcommerceException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EcommerceChannel;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EcommerceWebhookController extends Controller
{
    use ApiResponse;

    /**
     * Public webhook of a channel (no session): the channel is found by its UUID and the delivery is only processed
     * when the platform signature matches the channel secret.
     */
    public function __invoke(Request $request, EcommerceManager $manager, ProcessEcommerceWebhook $process, string $channelUuid): JsonResponse
    {
        $channel = Str::isUuid($channelUuid)
            ? EcommerceChannel::withoutGlobalScopes()->where('is_active', true)->find($channelUuid)
            : null;

        if ($channel === null) {
            return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), __('ecommerce.errors.unknown_channel'), Response::HTTP_NOT_FOUND);
        }

        try {
            if (! $manager->driver($channel)->verifyWebhookSignature($request)) {
                return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), __('ecommerce.errors.invalid_signature'), Response::HTTP_UNAUTHORIZED);
            }

            return $this->successResponse($process->execute($channel, $request), __('ecommerce.messages.webhook_processed'));
        } catch (EcommerceException $e) {
            return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), $e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }
    }
}
