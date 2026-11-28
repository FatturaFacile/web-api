<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Commands\BookLessonCommand;
use Modules\SkiOutdoorSchool\Application\Handlers\BillingHandler;
use Modules\SkiOutdoorSchool\Application\Handlers\LessonHandler;
use Modules\SkiOutdoorSchool\Application\Handlers\PackageHandler;
use Modules\SkiOutdoorSchool\Application\Queries\SchoolQueries;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\AssignLessonRequest;
use Modules\SkiOutdoorSchool\Http\Requests\BookLessonRequest;
use Modules\SkiOutdoorSchool\Http\Requests\CancelLessonRequest;
use Modules\SkiOutdoorSchool\Http\Requests\InvoiceRequest;
use Modules\SkiOutdoorSchool\Http\Requests\ReplacePackageRequest;
use Modules\SkiOutdoorSchool\Http\Requests\RescheduleLessonRequest;
use Modules\SkiOutdoorSchool\Http\Requests\UpdatePackageItemRequest;
use Modules\SkiOutdoorSchool\Http\Resources\LessonResource;
use Modules\SkiOutdoorSchool\Http\Resources\PackageItemResource;
use Modules\SkiOutdoorSchool\Models\PackageItem;
use Modules\SkiOutdoorSchool\Models\SkiLesson;
use OpenApi\Attributes as OA;

/**
 * Private lessons: booking with nominated instructor or fair rotation, assignment, rescheduling, cancellation, completion, the package
 * (rental, ARTVA kit, skipass) and the invoice with the IT / CH VAT exemption.
 */
final class LessonController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/lessons', operationId: 'skiSchoolLessonsIndex', summary: 'Private lessons (filter by status, discipline, instructor, source, unassigned, dates)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lessons')])]
    public function index(SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SkiLesson::class);

        return $this->paged($queries->lessons($this->filters(), $this->perPage()), LessonResource::class);
    }

    #[OA\Post(path: '/api/v1/ski-school/lessons', operationId: 'skiSchoolLessonsStore', summary: 'Book a private lesson (nominated instructor, fair rotation or manual)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Lesson')])]
    public function store(BookLessonRequest $request, LessonHandler $handler): JsonResponse
    {
        Gate::authorize('create', SkiLesson::class);
        $lesson = $handler->book(BookLessonCommand::fromArray($request->validated(), $this->userId()));

        return $this->createdResponse(new LessonResource($lesson->load(['instructor:id,first_name,last_name', 'packageItems'])), $this->message('lesson_booked'));
    }

    #[OA\Get(path: '/api/v1/ski-school/lessons/{id}', operationId: 'skiSchoolLessonsShow', summary: 'One lesson with its package', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lesson')])]
    public function show(string $id, SchoolQueries $queries): JsonResponse
    {
        $lesson = $queries->lesson($id);
        Gate::authorize('view', $lesson);

        return $this->successResponse(new LessonResource($lesson), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/ski-school/lessons/{id}/assign', operationId: 'skiSchoolLessonsAssign', summary: 'Assign the instructor of a lesson (without instructor_id the fair rotation picks)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lesson')])]
    public function assign(AssignLessonRequest $request, string $id, LessonHandler $handler, SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));
        $handler->assign($id, $request->validated('instructor_id'), $this->userId());

        return $this->successResponse(new LessonResource($queries->lesson($id)), $this->message('lesson_assigned'));
    }

    #[OA\Put(path: '/api/v1/ski-school/lessons/{id}/reschedule', operationId: 'skiSchoolLessonsReschedule', summary: 'Move an open lesson to another slot or instructor (conflicts are checked)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lesson')])]
    public function reschedule(RescheduleLessonRequest $request, string $id, LessonHandler $handler, SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));
        $handler->reschedule($id, $request->validated());

        return $this->successResponse(new LessonResource($queries->lesson($id)), $this->message('lesson_rescheduled'));
    }

    #[OA\Post(path: '/api/v1/ski-school/lessons/{id}/cancel', operationId: 'skiSchoolLessonsCancel', summary: 'Cancel a lesson or record a no-show', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lesson')])]
    public function cancel(CancelLessonRequest $request, string $id, LessonHandler $handler, SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));
        $handler->cancel($id, (bool) $request->validated('no_show', false));

        return $this->successResponse(new LessonResource($queries->lesson($id)), $this->message('lesson_cancelled'));
    }

    #[OA\Post(path: '/api/v1/ski-school/lessons/{id}/complete', operationId: 'skiSchoolLessonsComplete', summary: 'Mark a lesson as taught: records the fee of the instructor with the secretariat retention', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Lesson')])]
    public function complete(string $id, LessonHandler $handler, SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));
        $handler->complete($id);

        return $this->successResponse(new LessonResource($queries->lesson($id)), $this->message('lesson_completed'));
    }

    #[OA\Put(path: '/api/v1/ski-school/lessons/{id}/package', operationId: 'skiSchoolLessonsPackage', summary: 'Replace the package items (rental, ARTVA kit, skipass) of a lesson', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Package items')])]
    public function package(ReplacePackageRequest $request, string $id, PackageHandler $handler): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));

        return $this->successResponse(PackageItemResource::collection($handler->replaceForLesson($id, (array) ($request->validated('items') ?? []))), $this->message('package_saved'));
    }

    #[OA\Put(path: '/api/v1/ski-school/package-items/{id}', operationId: 'skiSchoolPackageItemsUpdate', summary: 'Hand over, take back or cancel a package item', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Package item')])]
    public function updateItem(UpdatePackageItemRequest $request, string $id, PackageHandler $handler): JsonResponse
    {
        Gate::authorize('update', PackageItem::query()->findOrFail($id));

        return $this->successResponse(new PackageItemResource($handler->setStatus($id, PackageItemStatus::from((string) $request->validated('status')))), $this->message('package_item_saved'));
    }

    #[OA\Post(path: '/api/v1/ski-school/lessons/{id}/invoice', operationId: 'skiSchoolLessonsInvoice', summary: 'Invoice a lesson with its package: instruction exempt (Art. 10 n. 20 DPR 633/72 / Art. 21 cpv. 2 n. 11 LIVA), add-ons taxed', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Invoices')])]
    public function invoice(InvoiceRequest $request, string $id, BillingHandler $handler): JsonResponse
    {
        Gate::authorize('update', SkiLesson::query()->findOrFail($id));

        return $this->createdResponse($handler->invoiceLesson($id, $request->validated('contact_id'), $this->userId()), $this->message('invoiced'));
    }
}
