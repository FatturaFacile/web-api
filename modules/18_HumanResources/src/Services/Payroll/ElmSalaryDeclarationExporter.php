<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LibXMLError;
use Modules\HumanResources\Contracts\Payroll\SalaryDeclarationExporterInterface;
use Modules\HumanResources\DTOs\Payroll\GenerateSalaryDeclarationDTO;
use Modules\HumanResources\Enums\Payroll\ContributionCode;
use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;
use Modules\HumanResources\Enums\Payroll\PayslipLineSide;
use Modules\HumanResources\Enums\Payroll\PayslipLineType;
use Modules\HumanResources\Exceptions\Payroll\SalaryDeclarationException;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;

/**
 * Builds an ELM-style salary declaration (element naming follows Swissdec ELM 5.0) from approved or closed
 * payroll runs of one year. The structure is designed to be aligned with the official XSD; it is validated
 * against it only when `payroll_ch.elm_xsd_path` points to an existing file. Nothing is transmitted.
 */
final readonly class ElmSalaryDeclarationExporter implements SalaryDeclarationExporterInterface
{
    private const MODULE_CODE = 'MODULE_18_HR';

    public function __construct(
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function generate(GenerateSalaryDeclarationDTO $dto): SalaryDeclaration
    {
        $runs = $this->declarableRuns($dto);

        /** @var Collection<int, Payslip> $payslips */
        $payslips = Payslip::query()
            ->with(['employee', 'profile', 'lines'])
            ->where('tenant_id', $dto->tenantId)
            ->whereIn('payroll_run_id', $runs->pluck('id')->all())
            ->orderBy('period_start')
            ->get();

        if ($payslips->isEmpty()) {
            throw SalaryDeclarationException::invalid(
                "The payroll runs of {$dto->year} contain no payslips.",
                ['payroll_run_ids' => ['The selected payroll runs contain no payslips.']],
            );
        }

        $this->assertDeclarable($payslips);

        $sequence = SalaryDeclaration::withTrashed()
            ->where('tenant_id', $dto->tenantId)
            ->where('period_year', $dto->year)
            ->count() + 1;
        $declarationNumber = sprintf('ELM-%04d-%03d', $dto->year, $sequence);

        $document = $this->buildDocument($dto, $declarationNumber, $payslips);
        $xsdValidated = $this->validateAgainstXsd($document);
        $xml = (string) $document->saveXML();

        $disk = (string) config('payroll_ch.declaration_disk', 'private');
        $path = trim((string) config('payroll_ch.declaration_directory', 'payroll/ch/declarations'), '/')
            ."/{$dto->tenantId}/{$declarationNumber}.xml";

        Storage::disk($disk)->put($path, $xml);

        $declaration = SalaryDeclaration::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $dto->tenantId,
            'company_id' => $dto->companyId,
            'declaration_number' => $declarationNumber,
            'period_year' => $dto->year,
            'status' => 'generated',
            'payroll_run_ids' => $runs->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all(),
            'employee_count' => $payslips->pluck('employee_id')->unique()->count(),
            'gross_rappen' => (int) $payslips->sum('gross_rappen'),
            'ahv_base_rappen' => (int) $payslips->sum('ahv_base_rappen'),
            'withholding_tax_rappen' => (int) $payslips->sum('withholding_tax_rappen'),
            'net_rappen' => (int) $payslips->sum('net_rappen'),
            'xml_disk' => $disk,
            'xml_path' => $path,
            'xml_sha256' => hash('sha256', $xml),
            'schema_version' => (string) config('payroll_ch.elm_schema_version', '5.0'),
            'xsd_validated' => $xsdValidated,
            'generated_at' => now(),
            'generated_by_user_id' => $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null,
        ]);

        $this->auditLedger->record(
            action: 'HR_PAYROLL_SALARY_DECLARATION_GENERATED',
            model: $declaration,
            payloadBefore: null,
            payloadAfter: [
                'declaration_number' => $declarationNumber,
                'xml_sha256' => $declaration->xml_sha256,
                'payroll_run_ids' => $declaration->payroll_run_ids,
            ],
            moduleCode: self::MODULE_CODE,
        );

        return $declaration;
    }

    /**
     * @return Collection<int, PayrollRun>
     */
    private function declarableRuns(GenerateSalaryDeclarationDTO $dto): Collection
    {
        $requestedIds = $dto->payrollRunIds;

        $runs = PayrollRun::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('period_year', $dto->year)
            ->whereIn('status', [PayrollRunStatus::Approved->value, PayrollRunStatus::Closed->value])
            ->when($requestedIds !== null && $requestedIds !== [], fn ($query) => $query->whereIn('id', $requestedIds ?? []))
            ->orderBy('period_month')
            ->get();

        if ($requestedIds !== null && $requestedIds !== []) {
            $found = $runs->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
            $missing = array_values(array_diff($requestedIds, $found));

            if ($missing !== []) {
                throw SalaryDeclarationException::invalid(
                    'Only approved or closed payroll runs of the declared year can be declared.',
                    ['payroll_run_ids' => array_map(static fn (string $id): string => "Payroll run {$id} is not an approved or closed run of {$dto->year}.", $missing)],
                );
            }
        }

        if ($runs->isEmpty()) {
            throw SalaryDeclarationException::invalid(
                "There are no approved or closed payroll runs for {$dto->year}.",
                ['year' => ["No approved or closed payroll runs for {$dto->year}."]],
            );
        }

        return $runs;
    }

    /**
     * Every declared employee needs an AHV number and a work canton.
     *
     * @param  Collection<int, Payslip>  $payslips
     */
    private function assertDeclarable(Collection $payslips): void
    {
        $problems = [];

        foreach ($payslips as $payslip) {
            $employeeId = (string) $payslip->employee_id;
            $profile = $payslip->profile;

            if ($profile === null) {
                $problems["employees.{$employeeId}.profile"] = ['The employee has no Swiss payroll profile.'];

                continue;
            }

            if ($profile->ahv_number === null || $profile->ahv_number === '') {
                $problems["employees.{$employeeId}.ahv_number"] = ['The AHV number is required for the salary declaration.'];
            }

            if ($profile->work_canton === '') {
                $problems["employees.{$employeeId}.work_canton"] = ['The work canton is required for the salary declaration.'];
            }
        }

        if ($problems !== []) {
            throw SalaryDeclarationException::invalid('Some employees lack the data required by the salary declaration.', $problems);
        }
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     */
    private function buildDocument(GenerateSalaryDeclarationDTO $dto, string $declarationNumber, Collection $payslips): DOMDocument
    {
        $namespace = (string) config('payroll_ch.elm_namespace');
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS($namespace, 'SalaryDeclaration');
        $root->setAttribute('schemaVersion', (string) config('payroll_ch.elm_schema_version', '5.0'));
        $document->appendChild($root);

        $general = $this->element($document, $root, 'GeneralSalaryDeclarationDescription');
        $this->element($document, $general, 'CreationDate', now()->toIso8601String());
        $this->element($document, $general, 'AccountingPeriod', (string) $dto->year);
        $this->element($document, $general, 'DeclarationNumber', $declarationNumber);

        $tenant = Tenant::query()->find($dto->tenantId);
        $company = $this->element($document, $root, 'Company');
        $companyDescription = $this->element($document, $company, 'CompanyDescription');
        $name = $this->element($document, $companyDescription, 'Name');
        $this->element($document, $name, 'HR-RC-Name', (string) ($tenant?->legal_name ?? $tenant?->name ?? ''));

        if ($tenant?->uid_number !== null && $tenant->uid_number !== '') {
            $this->element($document, $companyDescription, 'UID-BFS', (string) $tenant->uid_number);
        }

        $staff = $this->element($document, $company, 'Staff');

        foreach ($payslips->groupBy('employee_id') as $employeePayslips) {
            $this->appendPerson($document, $staff, $employeePayslips);
        }

        $totals = $this->element($document, $root, 'Totals');
        $this->element($document, $totals, 'EmployeeCount', (string) $payslips->pluck('employee_id')->unique()->count());
        $this->element($document, $totals, 'GrossSalary', Rappen::toDecimal((int) $payslips->sum('gross_rappen')));
        $this->element($document, $totals, 'AHV-AVS-Salary', Rappen::toDecimal((int) $payslips->sum('ahv_base_rappen')));
        $this->element($document, $totals, 'ALV-AC-Salary', Rappen::toDecimal((int) $payslips->sum('alv_base_rappen')));
        $this->element($document, $totals, 'TaxAtSource', Rappen::toDecimal((int) $payslips->sum('withholding_tax_rappen')));
        $this->element($document, $totals, 'EmployeeContributions', Rappen::toDecimal((int) $payslips->sum('employee_deductions_rappen')));
        $this->element($document, $totals, 'EmployerContributions', Rappen::toDecimal((int) $payslips->sum('employer_contributions_rappen')));
        $this->element($document, $totals, 'NetSalary', Rappen::toDecimal((int) $payslips->sum('net_rappen')));

        return $document;
    }

    /**
     * @param  SupportCollection<int, Payslip>  $payslips  payslips of one employee, ordered by period
     */
    private function appendPerson(DOMDocument $document, DOMElement $staff, SupportCollection $payslips): void
    {
        /** @var Payslip $first */
        $first = $payslips->first();
        /** @var PayrollProfile $profile */
        $profile = $first->profile;
        $employee = $first->employee;

        $person = $this->element($document, $staff, 'Person');

        $particulars = $this->element($document, $person, 'Particulars');
        $identification = $this->element($document, $particulars, 'Social-InsuranceIdentification');
        $this->element($document, $identification, 'SV-AS-Number', (string) $profile->ahv_number);
        $this->element($document, $particulars, 'EmployeeNumber', (string) ($employee?->employee_number ?? ''));
        $this->element($document, $particulars, 'Lastname', (string) ($employee?->last_name ?? ''));
        $this->element($document, $particulars, 'Firstname', (string) ($employee?->first_name ?? ''));

        if ($employee?->birth_date !== null) {
            $this->element($document, $particulars, 'DateOfBirth', $employee->birth_date->toDateString());
        }

        $this->element($document, $particulars, 'Nationality', (string) ($employee?->nationality ?? ''));
        $civilStatus = $this->element($document, $particulars, 'CivilStatus');
        $this->element($document, $civilStatus, 'Status', $profile->civil_status);

        $residence = $this->element($document, $particulars, 'Residence');
        $this->element($document, $residence, 'Country', $profile->residence_country);

        if ($profile->residence_canton !== null) {
            $this->element($document, $residence, 'Canton', $profile->residence_canton);
        }

        if ($profile->residence_municipality_bfs !== null) {
            $this->element($document, $residence, 'MunicipalityID', $profile->residence_municipality_bfs);
        }

        $work = $this->element($document, $person, 'Work');
        $this->element($document, $work, 'WorkplaceCanton', $profile->work_canton);

        if ($employee?->hire_date !== null) {
            $this->element($document, $work, 'EntryDate', $employee->hire_date->toDateString());
        }

        if ($employee?->termination_date !== null) {
            $this->element($document, $work, 'WithdrawalDate', $employee->termination_date->toDateString());
        }

        $this->element($document, $work, 'ActivityRate', (string) $first->fte_percentage);

        if ($profile->permit_type !== null) {
            $this->element($document, $work, 'ResidencePermit', $profile->permit_type);
        }

        $withholdingPayslip = $payslips->first(static fn (Payslip $payslip): bool => $payslip->qst_tariff_code !== null);

        if ($withholdingPayslip !== null) {
            $taxAtSource = $this->element($document, $person, 'TaxAtSource');
            $this->element($document, $taxAtSource, 'TaxAtSourceCanton', (string) $withholdingPayslip->qst_canton);
            $this->element($document, $taxAtSource, 'TaxAtSourceCategory', (string) $withholdingPayslip->qst_tariff_code);
        }

        $salaries = $this->element($document, $person, 'Salaries');

        foreach ($payslips as $payslip) {
            $this->appendSalary($document, $salaries, $payslip);
        }
    }

    private function appendSalary(DOMDocument $document, DOMElement $salaries, Payslip $payslip): void
    {
        $salary = $this->element($document, $salaries, 'Salary');

        $period = $this->element($document, $salary, 'Period');
        $this->element($document, $period, 'from', $payslip->period_start->toDateString());
        $this->element($document, $period, 'until', $payslip->period_end->toDateString());

        $amounts = $this->element($document, $salary, 'Amounts');
        $this->element($document, $amounts, 'GrossSalary', Rappen::toDecimal($payslip->gross_rappen));
        $this->element($document, $amounts, 'AHV-AVS-Salary', Rappen::toDecimal($payslip->ahv_base_rappen));
        $this->element($document, $amounts, 'ALV-AC-Salary', Rappen::toDecimal($payslip->alv_base_rappen));
        $this->element($document, $amounts, 'ALVZ-ACS-Salary', Rappen::toDecimal($payslip->alv_solidarity_base_rappen));
        $uvgSalaries = $this->element($document, $amounts, 'UVG-LAA-Salaries');
        $this->element($document, $uvgSalaries, 'UVG-LAA-Salary', Rappen::toDecimal($payslip->uvg_base_rappen));
        $bvgSalaries = $this->element($document, $amounts, 'BVG-LPP-Salaries');
        $this->element($document, $bvgSalaries, 'BVG-LPP-Salary', Rappen::toDecimal($payslip->bvg_insured_salary_rappen));
        $this->element($document, $amounts, 'TaxAtSourceSalary', Rappen::toDecimal($payslip->qst_base_rappen));
        $this->element($document, $amounts, 'NetSalary', Rappen::toDecimal($payslip->net_rappen));

        $employeeContributions = [];

        foreach ($payslip->lines as $line) {
            if ($line->side === PayslipLineSide::Employee && $line->line_type !== PayslipLineType::Earning) {
                $employeeContributions[$line->code] = ($employeeContributions[$line->code] ?? 0) + $line->amount_rappen;
            }
        }

        $deductions = $this->element($document, $salary, 'Deductions');
        $deductionElements = [
            ContributionCode::AhvIvEo->value => 'AHV-AVS-Contribution',
            ContributionCode::Alv->value => 'ALV-AC-Contribution',
            ContributionCode::AlvSolidarity->value => 'ALVZ-ACS-Contribution',
            ContributionCode::Bvg->value => 'BVG-LPP-Contribution',
            ContributionCode::UvgNbu->value => 'UVG-LAA-Contribution',
            ContributionCode::Ktg->value => 'KTG-AMC-Contribution',
            ContributionCode::WithholdingTax->value => 'TaxAtSource',
        ];

        foreach ($deductionElements as $code => $elementName) {
            $this->element($document, $deductions, $elementName, Rappen::toDecimal($employeeContributions[$code] ?? 0));
        }

        $wageTypes = $this->element($document, $salary, 'WageTypes');

        foreach ($payslip->lines as $line) {
            if ($line->line_type !== PayslipLineType::Earning) {
                continue;
            }

            $wageType = $this->element($document, $wageTypes, 'WageType', Rappen::toDecimal($line->amount_rappen));
            $wageType->setAttribute('code', $line->code);
        }
    }

    private function element(DOMDocument $document, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $document->createElementNS((string) config('payroll_ch.elm_namespace'), $name);

        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    /**
     * Validates against the official ELM XSD when one is configured and present on disk.
     *
     * @throws SalaryDeclarationException when the document does not match the schema
     */
    private function validateAgainstXsd(DOMDocument $document): bool
    {
        $xsdPath = config('payroll_ch.elm_xsd_path');

        if (! is_string($xsdPath) || $xsdPath === '' || ! is_file($xsdPath)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $valid = $document->schemaValidate($xsdPath);
            $messages = array_map(
                static fn (LibXMLError $error): string => trim($error->message).' (line '.$error->line.')',
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $valid) {
            throw SalaryDeclarationException::invalid('The generated salary declaration does not match the ELM schema.', ['xml' => $messages]);
        }

        return true;
    }
}
