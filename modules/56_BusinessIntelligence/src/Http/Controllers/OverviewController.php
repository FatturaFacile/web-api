<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiSnapshot;
use OpenApi\Attributes as OA;

/**
 * Summary of the business intelligence module for its landing page.
 */
final class OverviewController extends Controller
{
    use InteractsWithBi;

    #[OA\Get(path: '/api/v1/business-intelligence/overview', operationId: 'showBusinessIntelligenceOverview', description: 'Counts of what the caller can open (dashboards, saved queries, with the ones scheduled for snapshots), the calculated measures of the company, the latest dashboards and the failed snapshots of the last runs.', summary: 'Business intelligence overview', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Overview', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiOverview')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', BiDashboard::class);
        $user = $this->currentUser();
        $queries = BiSavedQuery::query()->visibleTo($user);

        return response()->json(['data' => [
            'dashboards' => BiDashboard::query()->visibleTo($user)->count(),
            'saved_queries' => (clone $queries)->count(),
            'scheduled_queries' => (clone $queries)->whereNotNull('snapshot_frequency')->count(),
            'calculated_measures' => BiCalculatedMeasure::query()->count(),
            'recent_dashboards' => BiDashboard::query()->visibleTo($user)->orderByDesc('updated_at')->limit(5)->get(['id', 'name', 'updated_at'])
                ->map(static fn (BiDashboard $dashboard): array => ['id' => $dashboard->id, 'name' => $dashboard->name, 'updated_at' => $dashboard->updated_at?->toIso8601String()])->all(),
            'failed_snapshots' => BiSnapshot::query()->where('status', BiSnapshot::Failed)->where('source_type', BiSnapshot::SavedQuery)
                ->whereIn('source_id', BiSavedQuery::query()->visibleTo($user)->select('id'))
                ->orderByDesc('created_at')->limit(5)->get(['id', 'source_id', 'taken_on', 'error'])
                ->map(static fn (BiSnapshot $snapshot): array => ['id' => $snapshot->id, 'source_id' => $snapshot->source_id, 'taken_on' => $snapshot->taken_on->toDateString(), 'error' => $snapshot->error])->all(),
        ]]);
    }
}
