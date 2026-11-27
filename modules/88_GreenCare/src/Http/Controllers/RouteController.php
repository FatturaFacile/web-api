<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Commands\OptimizeWeeklyCrewRoutesCommand;
use Modules\GreenCare\Application\Commands\RescheduleRoutesOnWeatherAlertCommand;
use Modules\GreenCare\Application\Handlers\OptimizeWeeklyCrewRoutesHandler;
use Modules\GreenCare\Application\Handlers\RescheduleRoutesOnWeatherAlertHandler;
use Modules\GreenCare\Application\Services\RouteWriter;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Domain\Weather\WeatherAlertEvaluator;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\NoInputRequest;
use Modules\GreenCare\Http\Requests\OptimizeRoutesRequest;
use Modules\GreenCare\Http\Requests\RescheduleWeatherRequest;
use Modules\GreenCare\Http\Requests\SaveRouteRequest;
use Modules\GreenCare\Http\Resources\RouteResource;
use Modules\GreenCare\Models\CrewRouteSchedule;
use OpenApi\Attributes as OA;

/**
 * Weekly crew routes: the planner board, the optimisation of the week and the weather reschedule.
 */
final class RouteController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['crew', 'stops.site', 'stops.contract'];

    #[OA\Get(path: '/api/v1/green-care/routes', operationId: 'greenCareRoutesIndex', summary: 'Route schedules of a period with their stops', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Routes')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CrewRouteSchedule::class);

        $routes = CrewRouteSchedule::query()
            ->with(self::WITH)
            ->when(request()->query('from') !== null, static fn ($query) => $query->whereDate('schedule_date', '>=', (string) request()->query('from')))
            ->when(request()->query('to') !== null, static fn ($query) => $query->whereDate('schedule_date', '<=', (string) request()->query('to')))
            ->when(request()->query('crew_team_id') !== null, static fn ($query) => $query->where('crew_team_id', (string) request()->query('crew_team_id')))
            ->when(request()->query('status') !== null, static fn ($query) => $query->where('status', (string) request()->query('status')))
            ->orderBy('schedule_date')
            ->orderBy('crew_team_id')
            ->limit(500)
            ->get();

        return $this->successResponse(RouteResource::collection($routes)->resolve(), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/green-care/routes', operationId: 'greenCareRoutesStore', summary: 'Plan a route by hand', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Route')])]
    public function store(SaveRouteRequest $request, RouteWriter $writer): JsonResponse
    {
        Gate::authorize('create', CrewRouteSchedule::class);

        return $this->createdResponse(new RouteResource($writer->create($request->validated(), $this->tenantId())), $this->message('route_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/routes/{id}', operationId: 'greenCareRoutesShow', summary: 'One route with its stops', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Route')])]
    public function show(string $id): JsonResponse
    {
        $route = CrewRouteSchedule::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('view', $route);

        return $this->successResponse(new RouteResource($route), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/green-care/routes/{id}', operationId: 'greenCareRoutesUpdate', summary: 'Change the header, status or stop order of a route', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Route')])]
    public function update(SaveRouteRequest $request, string $id, RouteWriter $writer): JsonResponse
    {
        $route = CrewRouteSchedule::query()->findOrFail($id);
        Gate::authorize('update', $route);

        return $this->successResponse(new RouteResource($writer->update($route, $request->validated())), $this->message('route_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/routes/{id}', operationId: 'greenCareRoutesDestroy', summary: 'Delete a route that has not started', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id, RouteWriter $writer): JsonResponse
    {
        $route = CrewRouteSchedule::query()->findOrFail($id);
        Gate::authorize('delete', $route);
        $writer->delete($route);

        return $this->successResponse(null, $this->message('route_deleted'));
    }

    #[OA\Post(path: '/api/v1/green-care/routes/optimize', operationId: 'greenCareRoutesOptimize', summary: 'Plan the due interventions of a week into optimised crew routes', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Routes, unassigned contracts and summary')])]
    public function optimize(OptimizeRoutesRequest $request, OptimizeWeeklyCrewRoutesHandler $handler): JsonResponse
    {
        Gate::authorize('create', CrewRouteSchedule::class);
        $data = $request->validated();
        $result = $handler->handle(new OptimizeWeeklyCrewRoutesCommand(Carbon::parse($data['week_start']), $data['crew_team_ids'] ?? null, (bool) ($data['refresh_weather'] ?? true)));

        return $this->successResponse([
            'routes' => RouteResource::collection($result['routes'])->resolve(),
            'unassigned' => $result['unassigned'],
            'summary' => $result['summary'],
        ], $this->message('routes_optimized'));
    }

    #[OA\Post(path: '/api/v1/green-care/routes/auto-reschedule-weather', operationId: 'greenCareRoutesAutoRescheduleWeather', summary: 'Move the outdoor stops hit by rain or frost to the next clear day', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Rescheduled stops and routes')])]
    public function autoRescheduleWeather(RescheduleWeatherRequest $request, RescheduleRoutesOnWeatherAlertHandler $handler): JsonResponse
    {
        Gate::authorize('create', CrewRouteSchedule::class);
        $data = $request->validated();
        $result = $handler->handle(new RescheduleRoutesOnWeatherAlertCommand(Carbon::parse($data['from']), Carbon::parse($data['to'])));

        return $this->successResponse([
            'rescheduled_stops' => $result['rescheduled_stops'],
            'affected_routes' => RouteResource::collection($result['affected_routes'])->resolve(),
            'routes' => RouteResource::collection($result['routes'])->resolve(),
        ], $this->message('routes_rescheduled'));
    }

    #[OA\Post(path: '/api/v1/green-care/routes/{id}/refresh-weather', operationId: 'greenCareRoutesRefreshWeather', summary: 'Refresh the weather outlook and alert of a route', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Route')])]
    public function refreshWeather(NoInputRequest $request, string $id, IWeatherForecastProvider $weather, WeatherAlertEvaluator $alerts): JsonResponse
    {
        $route = CrewRouteSchedule::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('update', $route);

        $site = $route->stops->map(static fn ($stop) => $stop->site)->first(static fn ($site): bool => $site !== null && $site->hasCoordinates());
        $latitude = $site?->latitude ?? $route->crew->depot_latitude;
        $longitude = $site?->longitude ?? $route->crew->depot_longitude;
        $forecast = $latitude === null || $longitude === null ? null : $weather->forecast((float) $latitude, (float) $longitude, $route->schedule_date);

        if ($forecast !== null) {
            $route->weather_forecast_summary = $forecast->toArray();
            $route->weather_alert_status = $alerts->evaluate($forecast);
            $route->save();
        }

        return $this->successResponse(new RouteResource($route), $this->message('weather_refreshed'));
    }
}
