<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Associations\DTOs\AreaAccess;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Http\Requests\AttendanceRequest;
use Modules\Associations\Http\Requests\SignAuthorizationRequest;
use Modules\Associations\Http\Requests\UpdateAreaContactRequest;
use Modules\Associations\Http\Resources\AreaMemberResource;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\Receipt;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\OpenApi\OperationArguments;
use Modules\Associations\Services\AttendanceService;
use Modules\Associations\Services\CardDocumentService;
use Modules\Associations\Services\CourseCalendar;
use Modules\Associations\Services\CredentialService;
use Modules\Associations\Services\FeeStandingService;
use Modules\Associations\Services\GuardianService;
use Modules\Associations\Services\PaymentLinkService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Services\ReceiptService;
use Modules\Associations\Services\RegistryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member area, for members, guardians and instructors. Everything starts from the access of the token: a person
 * can only reach itself and the minors in its care, an instructor only the own courses and never any amount.
 */
final class MemberAreaController extends Controller
{
    private const string Area = OperationArguments::Area;

    public function __construct(
        private readonly RegistryService $registry,
        private readonly CardDocumentService $cards,
        private readonly CredentialService $credentials,
        private readonly FeeStandingService $standings,
        private readonly PaymentLinkService $paymentLinks,
        private readonly ReceiptService $receipts,
        private readonly CourseCalendar $calendar,
        private readonly GuardianService $guardians,
        private readonly AttendanceService $attendance,
        private readonly PrivateFiles $files,
    ) {}

    #[AssociationGet('/api/v1/association-area/me', 'showAssociationAreaMe', 'Show who is using the area: own profile, minors in care, roles', self::Area)]
    public function me(Request $request): JsonResponse
    {
        $access = $this->access($request);

        return response()->json(['data' => [
            'roles' => $access->roles(),
            'contact' => ['first_name' => $access->contact->first_name, 'last_name' => $access->contact->last_name, 'email' => $access->contact->email, 'phone' => $access->contact->phone],
            'profile' => $access->self === null ? null : new AreaMemberResource($access->self),
            'wards' => AreaMemberResource::collection($access->wards),
        ]]);
    }

    #[AssociationPut('/api/v1/association-area/me/contact', 'updateAssociationAreaContact', 'Update the own contact data', 'AssociationAreaContactRequest', self::Area)]
    public function updateContact(UpdateAreaContactRequest $request): JsonResponse
    {
        $contact = $this->registry->updateContactData($this->access($request)->contact, $request->validated());

        return response()->json(['data' => ['email' => $contact->email, 'phone' => $contact->phone]]);
    }

    #[AssociationPut('/api/v1/association-area/members/{id}/contact', 'updateAssociationAreaWardContact', 'As a guardian, update the contact data of a minor in care', 'AssociationAreaContactRequest', self::Area)]
    public function updateWardContact(UpdateAreaContactRequest $request, string $id): JsonResponse
    {
        $access = $this->access($request);
        $member = $access->subject($id);
        abort_unless($access->isGuardianOf($member), 404);

        $contact = $this->registry->updateContactData($member->contact, $request->validated());

        return response()->json(['data' => ['email' => $contact->email, 'phone' => $contact->phone]]);
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/card', 'showAssociationAreaCard', 'Show the membership and the QR text of a person', self::Area)]
    public function card(Request $request, string $id): JsonResponse
    {
        $member = $this->access($request)->subject($id);
        $membership = $member->currentMembership();
        $credential = $this->credentials->currentQr($member);

        return response()->json(['data' => [
            'membership_id' => $membership?->id,
            'card_number' => $membership?->card_number,
            'status' => $membership?->status,
            'valid_to' => $membership?->valid_to?->toDateString(),
            'qr' => $membership === null || $credential === null ? null : $this->credentials->qrPayload($credential),
        ]]);
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/card/pdf', 'downloadAssociationAreaCard', 'Download the digital card as PDF', self::Area, file: true)]
    public function cardPdf(Request $request, string $id): Response
    {
        $member = $this->access($request)->subject($id);
        /** @var Membership $membership */
        $membership = $member->currentMembership() ?? abort(404);
        $membership->load(['member.contact', 'year', 'membershipType']);

        return $this->pdf($this->cards->render($membership), "card-{$membership->card_number}.pdf");
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/fees', 'listAssociationAreaFees', 'List fees, instalments and the state of a person', self::Area)]
    public function fees(Request $request, string $id): JsonResponse
    {
        $member = $this->access($request)->subject($id);
        $standing = $this->standings->of($member);

        return response()->json(['data' => [
            'standing' => $standing,
            'color' => $standing->color(),
            'assignments' => FeeAssignment::query()->with('instalments')->where('member_id', $member->id)->orderByDesc('starts_on')->get(),
        ]]);
    }

    #[AssociationPost('/api/v1/association-area/instalments/{id}/pay', 'payAssociationAreaInstalment', 'Create an online payment link for an instalment', null, self::Area)]
    public function pay(Request $request, string $id): JsonResponse
    {
        $access = $this->access($request);
        $instalment = FeeInstalment::query()->with('assignment')->findOrFail($id);
        $access->subject($instalment->assignment->member_id);

        $result = $this->paymentLinks->create($instalment);

        return response()->json(['data' => ['url' => $result['url'], 'amount' => $result['link']->amount, 'expires_at' => $result['link']->expires_at]], 201);
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/receipts', 'listAssociationAreaReceipts', 'List the receipts of a person', self::Area)]
    public function receiptList(Request $request, string $id): AnonymousResourceCollection
    {
        $member = $this->access($request)->subject($id);

        return AssociationRecordResource::collection(Receipt::query()->where('member_id', $member->id)->orderByDesc('issued_on')->get());
    }

    #[AssociationGet('/api/v1/association-area/receipts/{id}/pdf', 'downloadAssociationAreaReceipt', 'Download a receipt as PDF', self::Area, file: true)]
    public function receiptPdf(Request $request, string $id): Response
    {
        $receipt = Receipt::query()->findOrFail($id);
        $this->access($request)->subject($receipt->member_id);

        return $this->pdf($this->receipts->render($receipt), "receipt-{$receipt->number}.pdf");
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/calendar', 'showAssociationAreaCalendar', 'List the lessons and events of a person in a period', self::Area, query: ['from', 'to'])]
    public function memberCalendar(Request $request, string $id): JsonResponse
    {
        $member = $this->access($request)->subject($id);
        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->query('to', $from->addMonth()->toDateString()))->min($from->addMonths(6));

        $courses = Course::query()->whereIn('id', CourseEnrolment::query()->where('member_id', $member->id)->where('status', CourseEnrolmentStatus::Enrolled)->pluck('course_id'))->get();

        return response()->json(['data' => $courses->flatMap(fn (Course $course): array => $this->calendar->occurrences($course, $from, $to))->sortBy(['date', 'starts_at'])->values()]);
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/documents', 'listAssociationAreaDocuments', 'List the documents shared with a person', self::Area)]
    public function documents(Request $request, string $id): AnonymousResourceCollection
    {
        $member = $this->access($request)->subject($id);

        return AssociationRecordResource::collection($member->documents()->where('visible_to_member', true)->get());
    }

    #[AssociationGet('/api/v1/association-area/members/{memberId}/documents/{id}/download', 'downloadAssociationAreaDocument', 'Download a document shared with a person', self::Area, file: true)]
    public function downloadDocument(Request $request, string $memberId, string $id): StreamedResponse
    {
        $member = $this->access($request)->subject($memberId);
        $document = $member->documents()->where('visible_to_member', true)->findOrFail($id);

        return $this->files->download($document->disk, $document->path, $document->original_name);
    }

    #[AssociationGet('/api/v1/association-area/members/{id}/authorizations', 'listAssociationAreaAuthorizations', 'As a guardian, list the authorizations of a minor in care', self::Area)]
    public function authorizations(Request $request, string $id): AnonymousResourceCollection
    {
        $access = $this->access($request);
        $member = $access->subject($id);
        abort_unless($access->isGuardianOf($member), 404);

        return AssociationRecordResource::collection($member->authorizations()->orderByDesc('created_at')->get());
    }

    #[AssociationPost('/api/v1/association-area/members/{memberId}/authorizations/{id}/sign', 'signAssociationAreaAuthorization', 'As a guardian, sign an authorization of a minor in care', 'AssociationAuthorizationSignRequest', self::Area)]
    public function signAuthorization(SignAuthorizationRequest $request, string $memberId, string $id): AssociationRecordResource
    {
        $access = $this->access($request);
        $member = $access->subject($memberId);
        abort_unless($access->isGuardianOf($member), 404);

        $authorization = $member->authorizations()->findOrFail($id);

        return new AssociationRecordResource($this->guardians->sign($authorization, $access, (string) $request->validated('signed_name'), $request));
    }

    #[AssociationGet('/api/v1/association-area/instructor/courses', 'listAssociationAreaInstructorCourses', 'As an instructor, list the own courses', self::Area)]
    public function instructorCourses(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access($request)->courses->map(static fn (Course $course): array => [
            'id' => $course->id,
            'title' => $course->title,
            'kind' => $course->kind,
            'starts_on' => $course->starts_on->toDateString(),
            'ends_on' => $course->ends_on?->toDateString(),
        ])->values()]);
    }

    #[AssociationGet('/api/v1/association-area/instructor/courses/{id}/participants', 'listAssociationAreaInstructorParticipants', 'As an instructor, list the participants of an own course, without any fee data', self::Area)]
    public function instructorParticipants(Request $request, string $id): JsonResponse
    {
        $course = $this->access($request)->course($id);

        $participants = CourseEnrolment::query()->with('member.contact')->where('course_id', $course->id)
            ->where('status', CourseEnrolmentStatus::Enrolled)->get()
            ->map(static fn (CourseEnrolment $enrolment): array => ['member_id' => $enrolment->member_id, 'name' => $enrolment->member->displayName()]);

        return response()->json(['data' => $participants->values()]);
    }

    #[AssociationPost('/api/v1/association-area/instructor/courses/{id}/attendance', 'recordAssociationAreaAttendance', 'As an instructor, record the attendance of a day of an own course', 'AssociationAttendanceRequest', self::Area)]
    public function instructorAttendance(AttendanceRequest $request, string $id): JsonResponse
    {
        $access = $this->access($request);
        $course = $access->course($id);

        $result = $this->attendance->record($course, CarbonImmutable::parse($request->validated('date')), $request->validated('entries'), ['member_id' => $access->self?->id]);

        return response()->json(['data' => array_map(static fn (array $entry): array => array_diff_key($entry, ['package_exhausted' => true]), $result)]);
    }

    private function access(Request $request): AreaAccess
    {
        /** @var AreaAccess */
        return $request->attributes->get('areaAccess');
    }

    private function pdf(string $content, string $fileName): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($fileName)).'"',
        ]);
    }
}
