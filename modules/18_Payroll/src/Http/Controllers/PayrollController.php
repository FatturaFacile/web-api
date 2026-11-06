<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\PayrollRecord;

/**
 * What the payroll controllers share.
 */
abstract class PayrollController extends Controller
{
    public function __construct(protected readonly TenantContext $tenantContext) {}

    protected function tenant(): Tenant
    {
        return $this->tenantContext->getTenant();
    }

    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * An employee of the active company: the tenant scope answers 404 for the others.
     */
    protected function employee(string $employeeId): Employee
    {
        return Employee::query()->findOrFail($employeeId);
    }

    protected function perPage(Request $request, int $default, int $maximum = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $maximum));
    }

    protected function requireCountry(string $country, string $message): void
    {
        if (strtoupper((string) $this->tenant()->country) !== $country) {
            throw new PayrollException($message);
        }
    }

    /**
     * The response of a PUT that creates or changes a record: always 200.
     */
    protected function savedRecord(PayrollRecord $record): PayrollRecordResource
    {
        $record->refresh()->wasRecentlyCreated = false;

        return new PayrollRecordResource($record);
    }

    protected function pdfResponse(string $content, string $fileName): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($fileName)).'"',
        ]);
    }
}
