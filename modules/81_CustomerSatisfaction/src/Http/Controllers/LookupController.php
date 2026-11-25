<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryPriority;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Domain\Enums\ResponseBand;
use Modules\CustomerSatisfaction\Domain\Enums\SubmittedVia;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Domain\Enums\ThrottleReason;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Services\TeamDirectory;
use OpenApi\Attributes as OA;

/**
 * Enumerations, scales, locales, root causes and the team members the screens of the module need.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    #[OA\Get(path: '/api/v1/customer-satisfaction/lookups', operationId: 'customerSatisfactionLookups', summary: 'Enumerations, scales, locales, root causes and team members of the customer satisfaction module', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(TeamDirectory $team): JsonResponse
    {
        $scales = [];
        foreach (SurveyType::cases() as $type) {
            $scales[$type->value] = ['min' => $type->min(), 'max' => $type->max(), 'detractor_threshold' => $type->defaultDetractorThreshold()];
        }

        return $this->successResponse([
            'survey_types' => SurveyType::values(),
            'scales' => $scales,
            'trigger_events' => SurveyTriggerEvent::values(),
            'channels' => SurveyChannel::values(),
            'dispatch_statuses' => DispatchStatus::values(),
            'throttle_reasons' => ThrottleReason::values(),
            'bands' => ResponseBand::values(),
            'recovery_statuses' => RecoveryStatus::values(),
            'recovery_priorities' => RecoveryPriority::values(),
            'group_by' => ReportGroupBy::values(),
            'submitted_via' => SubmittedVia::values(),
            'locales' => SurveyLocale::Supported,
            'root_causes' => config('customer_satisfaction.root_causes'),
            'members' => $team->members($this->tenantId()),
        ], $this->message('loaded'));
    }
}
