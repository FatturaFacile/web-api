<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmployeeShift;
use App\Models\WorkSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Geofenced work sites and the employee shift calendar. */
class MobileWorkforceController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    public function workSites(): JsonResponse
    {
        return $this->successResponse(WorkSite::where('is_active', true)->orderBy('name')->get());
    }

    public function storeWorkSite(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(WorkSite::create($data), 'Work site created', Response::HTTP_CREATED);
    }

    public function updateWorkSite(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');

        $site = WorkSite::findOrFail($id);
        $site->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return $this->successResponse($site->refresh(), 'Work site updated');
    }

    public function destroyWorkSite(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');
        WorkSite::findOrFail($id)->delete();

        return $this->successResponse(null, 'Work site deleted');
    }

    /** Own shift calendar; managers can query another user with `user_id`. */
    public function shifts(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'uuid'],
        ]);

        $userId = $request->user()->id;
        if (! empty($params['user_id']) && $params['user_id'] !== $userId) {
            $this->authorizeAdmin($request, 'view-all-attendance');
            $userId = $params['user_id'];
        }

        $shifts = EmployeeShift::with('workSite:id,name')
            ->where('user_id', $userId)
            ->where('ends_at', '>=', $params['from'] ?? now()->startOfWeek())
            ->where('starts_at', '<=', $params['to'] ?? now()->endOfWeek()->addWeeks(3))
            ->orderBy('starts_at')
            ->get();

        return $this->successResponse($shifts);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-shifts');

        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'work_site_id' => ['nullable', 'uuid', 'exists:work_sites,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'shift_type' => ['nullable', 'in:regular,night,on_call,holiday'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = EmployeeShift::create($data + ['shift_type' => 'regular']);

        return $this->successResponse($shift->load('workSite:id,name'), 'Shift created', Response::HTTP_CREATED);
    }

    public function destroyShift(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-shifts');
        EmployeeShift::findOrFail($id)->delete();

        return $this->successResponse(null, 'Shift deleted');
    }
}
