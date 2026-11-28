<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AdventureParks\Domain\Services\SizeRecommender;
use Modules\AdventureParks\Http\Requests\SignWaiverRequest;
use Modules\AdventureParks\Http\Requests\SizeRecommendationRequest;
use Modules\AdventureParks\Http\Resources\TicketResource;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Services\WaiverService;
use OpenApi\Attributes as OA;

/**
 * Public waiver of a ticket: no sign-in, the unguessable token of the booking link is the credential.
 */
final class PublicWaiverController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WaiverService $waivers) {}

    #[OA\Get(path: '/api/v1/adventure-parks/public/waiver/{token}', operationId: 'adventureParksPublicWaiverShow', summary: 'Waiver page of a booking: legal text from the database, size table, QR payload', tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it', 'en', 'de', 'fr']))], responses: [new OA\Response(response: 200, description: 'Waiver page'), new OA\Response(response: 404, description: 'Unknown token')])]
    public function show(Request $request, string $token): JsonResponse
    {
        $locale = $request->query('locale');

        return $this->successResponse($this->waivers->withTicketByToken($token, fn (Ticket $ticket): array => $this->waivers->page($ticket, is_string($locale) ? $locale : null)));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/public/waiver/{token}/sign', operationId: 'adventureParksPublicWaiverSign', summary: 'Sign the waiver for the signer and the minors', tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Waiver signed'), new OA\Response(response: 422, description: 'headcount_mismatch or waiver_already_signed')])]
    public function sign(SignWaiverRequest $request, string $token): JsonResponse
    {
        $data = $request->validated();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        $result = $this->waivers->withTicketByToken($token, function (Ticket $ticket) use ($data, $ip, $userAgent): array {
            $signed = $this->waivers->sign((string) $ticket->id, $data, $ip, $userAgent);

            return [
                'signed' => true,
                'qr_payload' => $signed['ticket']->public_token,
                'participants' => TicketResource::make($signed['ticket'])->resolve()['participants'],
            ];
        });

        return $this->successResponse($result, __('adventure_parks.messages.waiver_signed'));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/public/size-recommendation', operationId: 'adventureParksPublicSizeRecommendation', summary: 'Harness size recommended for a height and a weight', tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'height_cm', in: 'query', required: true, schema: new OA\Schema(type: 'number')), new OA\Parameter(name: 'weight_kg', in: 'query', required: true, schema: new OA\Schema(type: 'number'))], responses: [new OA\Response(response: 200, description: 'Recommended size')])]
    public function recommendation(SizeRecommendationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(['size' => SizeRecommender::fromConfig()->recommend((float) $data['height_cm'], (float) $data['weight_kg'])->value]);
    }
}
