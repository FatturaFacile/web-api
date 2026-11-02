<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewTimeEntryCorrectionRequest;
use App\Http\Requests\StoreTimeEntryCorrectionRequest;
use App\Http\Resources\TimeEntryCorrectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TimeEntryCorrectionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AttendanceCorrectionService $service) {}

    public function index(string $entry): JsonResponse
    {
        $entry = TimeEntry::findOrFail($entry);
        abort_unless($entry->tenant_id === app(TenantContext::class)->getTenantId(), 404);
        Gate::authorize('viewForEntry', [TimeEntryCorrection::class, $entry]);

        return $this->successResponse(
            data: TimeEntryCorrectionResource::collection($entry->corrections()->latest()->get()),
            message: 'Attendance corrections retrieved successfully'
        );
    }

    public function store(StoreTimeEntryCorrectionRequest $request, string $entry): JsonResponse
    {
        $entry = TimeEntry::findOrFail($entry);
        /** @var User $actor */
        $actor = $request->user();

        return $this->correctionResponse(
            fn (): TimeEntryCorrection => $this->service->submit($entry, $actor, $request->validated()),
            201
        );
    }

    public function approve(ReviewTimeEntryCorrectionRequest $request, string $correction): JsonResponse
    {
        $correction = TimeEntryCorrection::findOrFail($correction);
        /** @var User $actor */
        $actor = $request->user();

        return $this->correctionResponse(fn (): TimeEntryCorrection => $this->service->review($correction, $actor, true, $request->validated('reason')));
    }

    public function reject(ReviewTimeEntryCorrectionRequest $request, string $correction): JsonResponse
    {
        $correction = TimeEntryCorrection::findOrFail($correction);
        /** @var User $actor */
        $actor = $request->user();

        return $this->correctionResponse(fn (): TimeEntryCorrection => $this->service->review($correction, $actor, false, $request->validated('reason')));
    }

    /** @param Closure(): TimeEntryCorrection $operation */
    private function correctionResponse(Closure $operation, int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse(
                data: new TimeEntryCorrectionResource($operation()),
                message: 'Attendance correction processed successfully',
                statusCode: $status
            );
        } catch (AttendanceStateException $exception) {
            return $this->problemDetailsResponse($exception->title, $exception->getMessage(), $exception->status);
        }
    }
}
