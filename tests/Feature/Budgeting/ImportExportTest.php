<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetImport;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Services\WorkbookWriter;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require_once __DIR__.'/Support/ControlHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = ctlTenant();
    $this->owner = ctlMember($this->tenant, 'owner');
    ctlAct($this->owner, $this->tenant);
    $this->expense = ctlAccount($this->tenant);
    $this->other = ctlAccount($this->tenant);
    $this->center = ctlCostCenter($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function impVersion(array $overrides = []): array
{
    return test()->postJson('/api/v1/budgeting/versions', ['name' => 'Budget '.Str::random(5), 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR', ...$overrides])->assertCreated()->json('data');
}

function impCsv(string $content, string $name = 'budget.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/**
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function impSheets(string $path): array
{
    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(static fn (mixed $cell): mixed => $cell, $row->toArray());
        }
        $sheets[] = [$sheet->getName(), $rows];
    }

    $reader->close();

    return $sheets;
}

test('the template is an Excel file with the columns of the import', function (): void {
    $version = impVersion();

    $response = $this->get("/api/v1/budgeting/versions/{$version['id']}/import-template")->assertOk();

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    $sheets = impSheets($response->baseResponse->getFile()->getPathname());
    expect($sheets[0][0])->toBe('Budget')->and($sheets[0][1][0])->toBe(['account_code', 'cost_center_code', 'project_number', 'period', 'amount', 'quantity', 'unit_price', 'notes']);
});

test('a csv file is validated without importing and then imported in merge mode', function (): void {
    $version = impVersion();
    $csv = "account_code;cost_center_code;project_number;period;amount;quantity;unit_price;notes\n"
        ."{$this->expense->account_code};{$this->center->code};;1;1000,50;10;100,05;January\n"
        ."{$this->expense->account_code};{$this->center->code};;2;500;;;\n"
        ."{$this->other->account_code};;;1;75.25;;;\n";

    $check = $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv), 'validate_only' => '1'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($check['status'])->toBe('validated')->and($check['total_rows'])->toBe(3)->and($check['error_rows'])->toBe(0)->and($check['imported_rows'])->toBe(0)
        ->and(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(0);

    $done = $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv), 'mode' => 'merge'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($done['status'])->toBe('imported')->and($done['imported_rows'])->toBe(3)->and($done['format'])->toBe('csv')->and($done['mode'])->toBe('merge');

    $line = BudgetLine::query()->where('version_id', $version['id'])->where('account_id', $this->expense->id)->where('period', 1)->sole();
    expect((string) $line->amount)->toBe('1000.50')->and((string) $line->quantity)->toBe('10.000')->and((string) $line->unit_price)->toBe('100.0500')->and($line->notes)->toBe('January')
        ->and($line->cost_center_id)->toBe($this->center->id)->and($line->source)->toBe('manual')
        ->and(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(3);

    $history = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/imports")->assertOk()->json('data');
    expect($history)->toHaveCount(2)->and(array_column($history, 'status'))->toContain('imported', 'validated');
});

test('every problem of the file is reported with its row and nothing is imported', function (): void {
    $version = impVersion();
    $csv = "account_code,cost_center_code,project_number,period,amount,quantity,unit_price,notes\n"
        ."{$this->expense->account_code},{$this->center->code},,1,100,,,\n"
        ."NOPE,{$this->center->code},,1,100,,,\n"
        ."{$this->expense->account_code},CC-NOPE,,2,100,,,\n"
        ."{$this->expense->account_code},,PRJ-NOPE,13,100,,,\n"
        ."{$this->expense->account_code},,,3,-5,,,\n"
        ."{$this->expense->account_code},,,3,10,abc,,\n"
        ."{$this->expense->account_code},{$this->center->code},,1,999,,,\n";

    $result = $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv)], ['Accept' => 'application/json'])->assertOk()->json('data');

    $fields = collect($result['errors'])->mapWithKeys(fn (array $error): array => [$error['row'].':'.$error['field'] => $error['message']]);
    expect($result['status'])->toBe('failed')->and($result['total_rows'])->toBe(7)->and($result['error_rows'])->toBe(6)->and($result['imported_rows'])->toBe(0)
        ->and($fields->keys()->all())->toBe(['3:account_code', '4:cost_center_code', '5:project_number', '5:period', '6:amount', '7:quantity', '8:period'])
        ->and($fields['3:account_code'])->toContain('NOPE')->and($fields['8:period'])->toContain('row 2')
        ->and(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(0);
});

test('replace mode empties the version first and merge keeps the other cells', function (): void {
    $version = impVersion();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/lines", ['account_id' => $this->other->id, 'period' => 5, 'amount' => '77'])->assertCreated();
    $csv = "account_code,period,amount\n{$this->expense->account_code},1,10\n";

    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv), 'mode' => 'merge'], ['Accept' => 'application/json'])->assertOk();
    expect(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(2);

    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv), 'mode' => 'replace'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'imported');
    expect(BudgetLine::query()->where('version_id', $version['id'])->pluck('account_id')->all())->toBe([$this->expense->id]);
});

test('an xlsx file is read and a quarterly version takes quarters spread over their months', function (): void {
    $version = impVersion(['granularity' => 'quarterly']);
    $path = app(WorkbookWriter::class)->write([[
        'name' => 'Budget',
        'headers' => ['account_code', 'cost_center_code', 'project_number', 'period', 'amount'],
        'rows' => [[$this->expense->account_code, $this->center->code, '', 1, 1000.0], [$this->expense->account_code, $this->center->code, '', 4, 300.0]],
    ]]);
    $file = new UploadedFile($path, 'budget.xlsx', null, null, true);

    $result = $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($result['status'])->toBe('imported')->and($result['format'])->toBe('xlsx')->and($result['imported_rows'])->toBe(2);
    $lines = BudgetLine::query()->where('version_id', $version['id'])->orderBy('period')->get();
    expect($lines->pluck('period')->all())->toBe([1, 2, 3, 10, 11, 12])->and((string) $lines[0]->amount)->toBe('333.33')->and((string) $lines[2]->amount)->toBe('333.34')->and((string) $lines[5]->amount)->toBe('100.00');
    @unlink($path);

    $bad = impCsv("account_code,period,amount\n{$this->expense->account_code},5,10\n");
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => $bad], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.errors.0.field', 'period');
});

test('the file must have the required columns, be a supported format and go into a draft', function (): void {
    $version = impVersion();

    $missing = $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv("account_code,period\nX,1\n")], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($missing['status'])->toBe('failed')->and($missing['errors'][0])->toMatchArray(['row' => 1, 'field' => 'amount']);

    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv('x', 'budget.xls')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv('x', 'budget.pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv('')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv("account_code,period,amount\n{$this->expense->account_code},1,10\n"), 'mode' => 'wipe'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('mode');

    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/lines", ['account_id' => $this->expense->id, 'period' => 1, 'amount' => '1'])->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertOk();
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv("account_code,period,amount\n{$this->expense->account_code},1,10\n")], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(BudgetImport::query()->where('status', 'imported')->count())->toBe(0);
});

test('read-only roles and other companies cannot import', function (): void {
    $version = impVersion();
    $csv = "account_code,period,amount\n{$this->expense->account_code},1,10\n";

    ctlAct(ctlMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv)], ['Accept' => 'application/json'])->assertForbidden();
    $this->get("/api/v1/budgeting/versions/{$version['id']}/import-template")->assertOk();

    $foreign = ctlTenant('Foreign');
    ctlAct(ctlMember($foreign, 'owner'), $foreign);
    $this->post("/api/v1/budgeting/versions/{$version['id']}/import", ['file' => impCsv($csv)], ['Accept' => 'application/json'])->assertNotFound();
    $this->get("/api/v1/budgeting/versions/{$version['id']}/executive-pack")->assertNotFound();
});

test('the executive pack is one workbook with the summary, the breakdown, the variance and the spend control', function (): void {
    $version = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
    ctlPost($this->tenant, $this->expense, '2028-02-10', '3300.00', $this->center);
    ctlRule($this->tenant, 'hard_block', ['name' => 'Centre lock', 'cost_center_id' => $this->center->id]);
    $this->postJson('/api/v1/budgeting/spend-control/overrides', ['scope' => 'allowance', 'cost_center_id' => $this->center->id, 'requested_amount' => '500', 'reason' => 'Season peak'])->assertCreated();

    $response = $this->get("/api/v1/budgeting/versions/{$version->id}/executive-pack?through_period=3")->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    $sheets = collect(impSheets($response->baseResponse->getFile()->getPathname()))->mapWithKeys(fn (array $sheet): array => [$sheet[0] => $sheet[1]]);

    expect($sheets->keys()->all())->toBe(['Summary', 'Breakdown', 'Versions', 'Rolling forecast', 'Variance by account', 'Variance by cost center', 'Volume and price', 'Spend rules', 'Open commitments', 'Overrides']);

    $summary = collect($sheets['Summary'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1] ?? null]);
    expect($summary['Version'])->toBe('Budget 2028')->and($summary['Fiscal year'])->toEqual(2028)->and($summary['Type'])->toBe('Original budget')->and($summary['Status'])->toBe('Approved')
        ->and($summary['Through period'])->toBe('2028-03')->and((float) $summary['Budget'])->toBe(3000.0)->and((float) $summary['Actual'])->toBe(3300.0)->and((float) $summary['Variance'])->toBe(300.0)
        ->and((float) $summary['Variance %'])->toBe(10.0)->and((float) $summary['Available budget'])->toBe(8700.0);

    expect($sheets['Breakdown'][0])->toContain('Account', 'Total')->and($sheets['Breakdown'])->toHaveCount(2)
        ->and($sheets['Variance by account'][1][6])->toBe('Amber')
        ->and($sheets['Spend rules'][1][0])->toBe('Centre lock')->and($sheets['Spend rules'][1][1])->toBe('Hard block')
        ->and($sheets['Overrides'][1][0])->toBe('Allowance')->and($sheets['Overrides'][1][4])->toBe('Pending')
        ->and($sheets['Versions'][1][1])->toBe('Original budget');
});

test('the executive pack follows the language of the user', function (): void {
    $version = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
    $this->owner->update(['locale' => 'it']);

    $response = $this->get("/api/v1/budgeting/versions/{$version->id}/executive-pack", ['Accept-Language' => 'it'])->assertOk();
    $names = array_column(impSheets($response->baseResponse->getFile()->getPathname()), 0);

    expect($names)->toContain('Sintesi', 'Dettaglio');
});
