<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Resources\CertificateResource;
use Modules\TalentLearning\Models\LmsCertificate;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use OpenApi\Attributes as OA;

/**
 * Completion certificates.
 */
final class CertificateController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly EmployeeAnnotator $annotator, private readonly HrDirectory $directory) {}

    #[OA\Get(path: '/api/v1/talent-learning/certificates', operationId: 'listTalentCertificates', description: 'Certificates of every employee, latest first. For learning managers. Filters: course_id, employee_id, validity (valid, expired, expiring for the ones that expire within 90 days).', summary: 'List certificates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Certificates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentCertificate'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage', LmsCertificate::class);
        $filters = $request->validate([
            'course_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'validity' => ['nullable', 'in:valid,expired,expiring'],
        ]);
        $today = Carbon::today()->toDateString();

        $page = LmsCertificate::query()->with('course')->where('tenant_id', $this->tenantId())
            ->when(isset($filters['course_id']), static fn ($query) => $query->where('course_id', $filters['course_id']))
            ->when(isset($filters['employee_id']), static fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(($filters['validity'] ?? null) === 'valid', static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('expires_on')->orWhere('expires_on', '>=', $today)))
            ->when(($filters['validity'] ?? null) === 'expired', static fn ($query) => $query->where('expires_on', '<', $today))
            ->when(($filters['validity'] ?? null) === 'expiring', static fn ($query) => $query->whereBetween('expires_on', [$today, Carbon::today()->addDays(90)->toDateString()]))
            ->orderByDesc('issued_on')->orderByDesc('certificate_number')
            ->paginate($this->perPage());
        $this->annotator->annotate($this->tenantId(), $page->getCollection());

        return CertificateResource::collection($page);
    }

    #[OA\Get(path: '/api/v1/talent-learning/my/certificates', operationId: 'listMyTalentCertificates', description: 'The certificates of the employee linked to the logged-in user. Empty when the user has no employee record.', summary: 'List my certificates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Certificates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentCertificate'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function mine(): AnonymousResourceCollection
    {
        $employee = $this->directory->employeeOfUser($this->tenantId(), $this->userId());

        return CertificateResource::collection(
            $employee === null ? collect() : LmsCertificate::query()->with('course')->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->orderByDesc('issued_on')->get(),
        );
    }
}
