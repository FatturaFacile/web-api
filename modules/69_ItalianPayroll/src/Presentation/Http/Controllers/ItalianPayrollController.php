<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Modules\ItalianPayroll\Application\Commands\ExportCdlAttendanceTrackCommand;
use Modules\ItalianPayroll\Application\Commands\GenerateSepaSalaryPain001Command;
use Modules\ItalianPayroll\Application\Commands\ImportCedoliniPdfBundleCommand;
use Modules\ItalianPayroll\Application\Commands\ImportProspettoContabilePagheCommand;
use Modules\ItalianPayroll\Application\Commands\ValidateMonthlyLulAttendanceCommand;
use Modules\ItalianPayroll\Application\Handlers\EmployeePayslipVaultHandler;
use Modules\ItalianPayroll\Application\Handlers\ExportCdlAttendanceTrackHandler;
use Modules\ItalianPayroll\Application\Handlers\GenerateSepaSalaryPain001Handler;
use Modules\ItalianPayroll\Application\Handlers\ImportCedoliniPdfBundleHandler;
use Modules\ItalianPayroll\Application\Handlers\ImportProspettoContabilePagheHandler;
use Modules\ItalianPayroll\Application\Handlers\PayrollQueriesHandler;
use Modules\ItalianPayroll\Application\Handlers\ValidateMonthlyLulAttendanceHandler;
use Modules\ItalianPayroll\Application\Queries\GetEmployeeCedolinoDownloadAuditQuery;
use Modules\ItalianPayroll\Application\Queries\GetF24PayrollTaxSummaryQuery;
use Modules\ItalianPayroll\Application\Queries\GetTfrAccrualLedgerQuery;
use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;
use Modules\ItalianPayroll\Domain\Enums\GlRole;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Export\ExportFile;
use Modules\ItalianPayroll\Infrastructure\Models\CausalMapping;
use Modules\ItalianPayroll\Infrastructure\Models\GlAccountSetting;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollAccountingImport;
use Modules\ItalianPayroll\Presentation\Http\Requests\ExportAttendanceRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\GenerateSepaRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\ImportAccountingRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\ImportCedoliniRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\MarkPeriodPaidRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\OpenPayslipRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\SaveCausalMappingsRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\SaveGlAccountsRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\StorePayrollPeriodRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\TfrLedgerRequest;
use Modules\ItalianPayroll\Presentation\Http\Requests\ValidateAttendanceRequest;
use Modules\ItalianPayroll\Presentation\Http\Resources\PayrollPeriodResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class ItalianPayrollController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/italian-payroll/periods', operationId: 'listItalianPayrollPeriods', description: 'The payroll months of the tenant with their closing state.', summary: 'List payroll periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function index(): JsonResponse
    {
        return $this->successResponse(PayrollPeriodResource::collection(ItalianPayrollPeriod::query()->withExists('accountingImport')->orderByDesc('year')->orderByDesc('month')->get())->resolve());
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods', operationId: 'createItalianPayrollPeriod', description: 'Opens a payroll month for a legal entity, in the attendance_open state.', summary: 'Open a payroll period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function store(StorePayrollPeriodRequest $request): JsonResponse
    {
        $data = $request->validated();
        $exists = ItalianPayrollPeriod::query()->where('year', $data['year'])->where('month', $data['month'])
            ->where('legal_entity_id', $data['legal_entity_id'] ?? null)->exists();

        if ($exists) {
            return $this->problemDetailsResponse('Conflict', 'Il periodo paghe esiste già.', Response::HTTP_CONFLICT);
        }

        $period = ItalianPayrollPeriod::query()->create($data + ['status' => PeriodStatus::AttendanceOpen->value]);

        return $this->successResponse((new PayrollPeriodResource($period))->resolve(), 'Periodo paghe creato', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/italian-payroll/periods/{id}', operationId: 'getItalianPayrollPeriod', description: 'One payroll month with its state and validation report.', summary: 'Show a payroll period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse((new PayrollPeriodResource(ItalianPayrollPeriod::query()->findOrFail($id)))->resolve());
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/validate', operationId: 'validateMonthlyLulAttendance', description: 'Aggregates clock entries, approved leaves, meals, expenses and fringe benefits into LUL lines and runs the consistency checks; with no error the month moves to validated.', summary: 'Validate the monthly attendance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function validateAttendance(string $id, ValidateAttendanceRequest $request, ValidateMonthlyLulAttendanceHandler $handler): JsonResponse
    {
        $fringe = [];

        foreach ($request->validated('fringe_benefits', []) as $row) {
            $fringe[(string) $row['employee_id']] = $this->money($row['amount']);
        }

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ValidateMonthlyLulAttendanceCommand($id, $this->actorId(), $fringe))));
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/export', operationId: 'exportCdlAttendanceTrack', description: 'Serializes the validated attendance in the layout of Zucchetti, TeamSystem, Inaz, GIS or a generic CSV.', summary: 'Export the attendance to the labour consultant', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function exportAttendance(string $id, ExportAttendanceRequest $request, ExportCdlAttendanceTrackHandler $handler): Response|JsonResponse
    {
        return $this->guarded(fn (): Response => $this->file($handler->handle(new ExportCdlAttendanceTrackCommand(
            $id,
            ConsultantFormat::from((string) $request->validated('consultant_format')),
            strtoupper((string) $request->validated('company_code')),
            $this->actorId(),
        ))));
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/cedolini', operationId: 'importCedoliniPdfBundle', description: 'Splits the consultant bulk PDF by codice fiscale, encrypts each payslip and links it to the employee.', summary: 'Import the payslip PDF bundle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function importCedolini(string $id, ImportCedoliniRequest $request, ImportCedoliniPdfBundleHandler $handler): JsonResponse
    {
        $pdf = (string) $request->file('bundle_pdf')?->get();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ImportCedoliniPdfBundleCommand($id, $pdf, $this->actorId()))));
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/accounting-import', operationId: 'importProspettoContabilePaghe', description: 'Checks that the summary reconciles and posts it as one balanced journal entry.', summary: 'Import the accounting summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function importAccounting(string $id, ImportAccountingRequest $request, ImportProspettoContabilePagheHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $summary = [
            'gross' => $this->money($data['total_gross_wages']), 'inps_employee' => $this->money($data['inps_employee_withholding']),
            'inps_employer' => $this->money($data['inps_employer_contribution']), 'irpef' => $this->money($data['irpef_withholding']),
            'addizionali' => $this->money($data['regional_municipal_addizionali']), 'tfr_accrual' => $this->money($data['tfr_monthly_accrual']),
            'tfr_fund' => $this->money($data['tfr_fondo_tesoreria_or_previdenza']), 'net' => $this->money($data['net_payable']),
            'inail' => $this->money($data['inail_accrual'] ?? 0),
        ];
        $nets = array_map(fn (array $row): array => ['employee_id' => (string) $row['employee_id'], 'net' => $this->money($row['net_amount'])], $data['employee_nets'] ?? []);

        return $this->guarded(function () use ($handler, $id, $summary, $nets, $data): JsonResponse {
            $import = $handler->handle(new ImportProspettoContabilePagheCommand($id, $summary, $nets, $this->actorId(), $data['company_id'] ?? null));

            return $this->successResponse(['id' => (string) $import->id, 'gl_journal_entry_id' => $import->gl_journal_entry_id, 'net_payable' => (string) $import->net_payable], 'Prospetto contabile registrato', Response::HTTP_CREATED);
        });
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/sepa', operationId: 'generateSepaSalaryPain001', description: 'ISO 20022 pain.001.001.03 credit transfer file with the SALA category purpose and the exact net amounts.', summary: 'Generate the SEPA salary file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function sepa(string $id, GenerateSepaRequest $request, GenerateSepaSalaryPain001Handler $handler): Response|JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): Response => $this->file($handler->handle(new GenerateSepaSalaryPain001Command(
            $id,
            ['name' => (string) $data['debtor_name'], 'iban' => (string) $data['debtor_iban'], 'bic' => $data['debtor_bic'] ?? null],
            (string) $data['execution_date'],
        ))));
    }

    #[OA\Post(path: '/api/v1/italian-payroll/periods/{id}/mark-paid', operationId: 'markItalianPayrollPeriodPaid', description: 'Closes the workflow once the salaries are paid.', summary: 'Mark the period as paid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function markPaid(string $id, MarkPeriodPaidRequest $request): JsonResponse
    {
        return $this->guarded(function () use ($id): JsonResponse {
            $period = DB::transaction(function () use ($id): ItalianPayrollPeriod {
                $period = ItalianPayrollPeriod::query()->lockForUpdate()->findOrFail($id);

                if (! PayrollAccountingImport::query()->where('payroll_period_id', $period->id)->exists()) {
                    throw new ItalianPayrollException('Non si può chiudere il periodo come pagato senza prospetto contabile.');
                }

                $period->advanceTo(PeriodStatus::Paid, $this->actorId());

                return $period;
            });

            return $this->successResponse((new PayrollPeriodResource($period))->resolve());
        });
    }

    #[OA\Get(path: '/api/v1/italian-payroll/periods/{id}/f24', operationId: 'getF24PayrollTaxSummary', description: 'IRPEF and surtax lines of the month with the due date.', summary: 'F24 payroll tax summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function f24(string $id, PayrollQueriesHandler $queries): JsonResponse
    {
        return $this->successResponse($queries->f24Summary(new GetF24PayrollTaxSummaryQuery($id)));
    }

    #[OA\Get(path: '/api/v1/italian-payroll/periods/{id}/download-audit', operationId: 'getEmployeeCedolinoDownloadAudit', description: 'The delivery and opening receipts of the payslips of a month.', summary: 'Payslip download audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function downloadAudit(string $id, PayrollQueriesHandler $queries): JsonResponse
    {
        return $this->successResponse($queries->downloadAudit(new GetEmployeeCedolinoDownloadAuditQuery($id, request()->query('employee_id') ? (string) request()->query('employee_id') : null)));
    }

    #[OA\Get(path: '/api/v1/italian-payroll/tfr-ledger', operationId: 'getTfrAccrualLedger', description: 'Monthly TFR accruals of a year with an optional revaluation projection.', summary: 'TFR accrual ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function tfrLedger(TfrLedgerRequest $request, PayrollQueriesHandler $queries): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($queries->tfrLedger(new GetTfrAccrualLedgerQuery(
            (int) $data['year'],
            $data['legal_entity_id'] ?? null,
            isset($data['foi_percent']) ? (string) $data['foi_percent'] : null,
        )));
    }

    #[OA\Put(path: '/api/v1/italian-payroll/gl-accounts', operationId: 'saveItalianPayrollGlAccounts', description: 'Maps each payroll posting role to an account of the chart of accounts.', summary: 'Set the payroll GL accounts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function saveGlAccounts(SaveGlAccountsRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->validated('accounts') as $row) {
                GlAccountSetting::query()->updateOrCreate(['role' => GlRole::from((string) $row['role'])->value], ['account_id' => $row['account_id']]);
            }
        });

        return $this->successResponse(GlAccountSetting::query()->pluck('account_id', 'role')->all());
    }

    #[OA\Put(path: '/api/v1/italian-payroll/causal-mappings', operationId: 'saveItalianPayrollCausalMappings', description: 'Overrides the voice code of an internal causal for one consultant software.', summary: 'Set the consultant voice codes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function saveCausalMappings(SaveCausalMappingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            foreach ($data['mappings'] as $row) {
                CausalMapping::query()->updateOrCreate(
                    ['consultant_format' => $data['consultant_format'], 'causal_code' => $row['causal_code']],
                    ['external_code' => $row['external_code']],
                );
            }
        });

        return $this->successResponse(CausalMapping::query()->where('consultant_format', $data['consultant_format'])->pluck('external_code', 'causal_code')->all());
    }

    #[OA\Get(path: '/api/v1/italian-payroll/my-payslips', operationId: 'listMyPayslips', description: 'The payslips and CU of the signed-in employee.', summary: 'My payslips', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function myPayslips(EmployeePayslipVaultHandler $vault): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($vault->list((string) $this->actorId())));
    }

    #[OA\Post(path: '/api/v1/italian-payroll/my-payslips/{distributionId}/open', operationId: 'openMyPayslip', description: 'Decrypts the PDF and writes the audit receipt required by L. 4/1953.', summary: 'Open one of my payslips', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian Payroll'], parameters: [new OA\Parameter(name: 'distributionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'OK'), new OA\Response(response: 422, description: 'A payroll rule was violated')])]
    public function openMyPayslip(string $distributionId, OpenPayslipRequest $request, EmployeePayslipVaultHandler $vault): Response|JsonResponse
    {
        return $this->guarded(function () use ($distributionId, $request, $vault): Response {
            /** @var 'cedolino'|'cu' $kind */
            $kind = (string) $request->validated('document_kind');
            $pdf = $vault->open((string) $this->actorId(), $distributionId, $kind, $request->ip(), $request->userAgent(), (bool) $request->validated('biometric_confirmed', false));

            return new HttpResponse($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$kind.'.pdf"', 'Cache-Control' => 'no-store']);
        });
    }

    private function file(ExportFile $file): Response
    {
        return new HttpResponse($file->content, 200, [
            'Content-Type' => $file->mimeType.'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'X-Content-Checksum-SHA256' => $file->checksum(),
        ]);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function actorId(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? (string) $user->id : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ItalianPayrollException $exception) {
            return $this->problemDetailsResponse('Payroll Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
