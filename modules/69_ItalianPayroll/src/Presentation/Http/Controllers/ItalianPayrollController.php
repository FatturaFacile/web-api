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
use Symfony\Component\HttpFoundation\Response;

final class ItalianPayrollController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->successResponse(PayrollPeriodResource::collection(ItalianPayrollPeriod::query()->orderByDesc('year')->orderByDesc('month')->get())->resolve());
    }

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

    public function show(string $id): JsonResponse
    {
        return $this->successResponse((new PayrollPeriodResource(ItalianPayrollPeriod::query()->findOrFail($id)))->resolve());
    }

    public function validateAttendance(string $id, ValidateAttendanceRequest $request, ValidateMonthlyLulAttendanceHandler $handler): JsonResponse
    {
        $fringe = [];

        foreach ($request->validated('fringe_benefits', []) as $row) {
            $fringe[(string) $row['employee_id']] = $this->money($row['amount']);
        }

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ValidateMonthlyLulAttendanceCommand($id, $this->actorId(), $fringe))));
    }

    public function exportAttendance(string $id, ExportAttendanceRequest $request, ExportCdlAttendanceTrackHandler $handler): Response|JsonResponse
    {
        return $this->guarded(fn (): Response => $this->file($handler->handle(new ExportCdlAttendanceTrackCommand(
            $id,
            ConsultantFormat::from((string) $request->validated('consultant_format')),
            strtoupper((string) $request->validated('company_code')),
            $this->actorId(),
        ))));
    }

    public function importCedolini(string $id, ImportCedoliniRequest $request, ImportCedoliniPdfBundleHandler $handler): JsonResponse
    {
        $pdf = (string) $request->file('bundle_pdf')?->get();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ImportCedoliniPdfBundleCommand($id, $pdf, $this->actorId()))));
    }

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

    public function sepa(string $id, GenerateSepaRequest $request, GenerateSepaSalaryPain001Handler $handler): Response|JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): Response => $this->file($handler->handle(new GenerateSepaSalaryPain001Command(
            $id,
            ['name' => (string) $data['debtor_name'], 'iban' => (string) $data['debtor_iban'], 'bic' => $data['debtor_bic'] ?? null],
            (string) $data['execution_date'],
        ))));
    }

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

    public function f24(string $id, PayrollQueriesHandler $queries): JsonResponse
    {
        return $this->successResponse($queries->f24Summary(new GetF24PayrollTaxSummaryQuery($id)));
    }

    public function downloadAudit(string $id, PayrollQueriesHandler $queries): JsonResponse
    {
        return $this->successResponse($queries->downloadAudit(new GetEmployeeCedolinoDownloadAuditQuery($id, request()->query('employee_id') ? (string) request()->query('employee_id') : null)));
    }

    public function tfrLedger(TfrLedgerRequest $request, PayrollQueriesHandler $queries): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($queries->tfrLedger(new GetTfrAccrualLedgerQuery(
            (int) $data['year'],
            $data['legal_entity_id'] ?? null,
            isset($data['foi_percent']) ? (string) $data['foi_percent'] : null,
        )));
    }

    public function saveGlAccounts(SaveGlAccountsRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->validated('accounts') as $row) {
                GlAccountSetting::query()->updateOrCreate(['role' => GlRole::from((string) $row['role'])->value], ['account_id' => $row['account_id']]);
            }
        });

        return $this->successResponse(GlAccountSetting::query()->pluck('account_id', 'role')->all());
    }

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

    public function myPayslips(EmployeePayslipVaultHandler $vault): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($vault->list((string) $this->actorId())));
    }

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
