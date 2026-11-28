<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use OpenApi\Attributes as OA;

/**
 * Reference data of the module: regimes with their jurisdiction, currency and CU causal, plus the statutory parameters the
 * screens show next to the simulator (thresholds, rates).
 */
final class LookupController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/withholding-recipients/lookups', operationId: 'withholdingRecipientsLookups', summary: 'Regimes, causals and statutory parameters', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);

        return $this->successResponse([
            'regimes' => array_map(static fn (RecipientRegime $regime): array => [
                'value' => $regime->value,
                'jurisdiction' => $regime->jurisdiction()->value,
                'currency' => $regime->currency(),
                'cu_causal' => $regime->defaultCuCausal(),
            ], RecipientRegime::cases()),
            'cu_causals' => config('withholding_recipients.cu_causals'),
            'activity_types' => array_map(static fn (ActivityType $type): string => $type->value, ActivityType::cases()),
            'enasarco_mandates' => array_map(static fn (EnasarcoMandate $mandate): string => $mandate->value, EnasarcoMandate::cases()),
            'parameters' => [
                'occasional_inps_threshold' => config('withholding_recipients.occasional.inps_threshold'),
                'stamp_threshold' => config('withholding_recipients.occasional.stamp_threshold'),
                'stamp_amount' => config('withholding_recipients.occasional.stamp_amount'),
                'sports_exempt_threshold' => config('withholding_recipients.amateur_sports.exempt_threshold'),
                'sports_irpef_threshold' => config('withholding_recipients.amateur_sports.irpef_threshold'),
                'swiss_de_minimis' => config('withholding_recipients.swiss.de_minimis'),
                'enasarco_ceiling' => config('withholding_recipients.agent.enasarco.ceiling'),
            ],
        ]);
    }
}
