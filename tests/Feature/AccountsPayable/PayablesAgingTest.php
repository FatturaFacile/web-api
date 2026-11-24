<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

/**
 * Alfa: 100 due in 5 days and 50 due today (not yet overdue), then 200 (10 days late), 300 (45), 400 (75) and 500 (120 days late),
 * and a credit note of 80. Beta: 10 (30 days late), 1000 (31), 20 (90) and 30 (91 days late).
 */
beforeEach(function (): void {
    apSetUp($this);
    $this->beta = apSupplier($this->tenant, 'Fornitore Beta SPA', 'IT84S0542811101000000654321');

    foreach ([['A-C1', '100.00', 5], ['A-C2', '50.00', 0], ['A-O1', '200.00', -10], ['A-O2', '300.00', -45], ['A-O3', '400.00', -75], ['A-O4', '500.00', -120]] as [$number, $amount, $due]) {
        apInvoice($this, $this->supplier, $number, $amount, $due, ['date' => now()->subDays(200)->toDateString()]);
    }

    apCreditNote($this, $this->supplier, 'A-NC', '80.00', -10);

    foreach ([['B-1', '10.00', -30], ['B-2', '1000.00', -31], ['B-3', '20.00', -90], ['B-4', '30.00', -91]] as [$number, $amount, $due]) {
        apInvoice($this, $this->beta, $number, $amount, $due, ['date' => now()->subDays(200)->toDateString()]);
    }
});

function apAging(object $test, string $query = ''): array
{
    return $test->getJson('/api/v1/accounts-payable/aging'.($query === '' ? '' : '?'.$query))->assertOk()->json('data');
}

/**
 * The rows of every sheet of an .xlsx content, by sheet name.
 *
 * @return array<string, list<list<mixed>>>
 */
function apWorkbook(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'apx').'.xlsx';
    file_put_contents($path, $content);
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $sheets;
}

it('ages the payables by supplier in the five buckets, with the credit notes next to them', function (): void {
    $report = apAging($this);

    expect($report)->toMatchArray(['as_of' => now()->toDateString(), 'currency' => 'EUR', 'available_currencies' => ['EUR']])
        ->and(array_column($report['buckets'], 'key'))->toBe(['current', '1_30', '31_60', '61_90', 'over_90'])
        ->and($report['buckets'][1])->toBe(['key' => '1_30', 'from' => 1, 'to' => 30])
        ->and($report['buckets'][4])->toBe(['key' => 'over_90', 'from' => 91, 'to' => null])
        ->and($report['summary']['buckets'])->toBe(['current' => '150.00', '1_30' => '210.00', '31_60' => '1300.00', '61_90' => '420.00', 'over_90' => '530.00'])
        ->and($report['summary'])->toMatchArray(['total_open' => '2610.00', 'total_overdue' => '2460.00', 'credit_notes' => '80.00', 'net_total' => '2530.00', 'over_90' => '530.00', 'held_total' => '0.00', 'suppliers_count' => 2, 'items_count' => 11]);

    // The suppliers with the most overdue come first, and their buckets add up to the summary.
    [$alfa, $beta] = $report['suppliers'];

    expect($alfa)->toMatchArray(['supplier_name' => 'Fornitore Alfa SRL', 'total_open' => '1550.00', 'total_overdue' => '1400.00', 'credit_notes' => '80.00', 'net_total' => '1470.00', 'over_90' => '500.00', 'items_count' => 7])
        ->and($alfa['buckets'])->toBe(['current' => '150.00', '1_30' => '200.00', '31_60' => '300.00', '61_90' => '400.00', 'over_90' => '500.00'])
        ->and($beta)->toMatchArray(['supplier_name' => 'Fornitore Beta SPA', 'total_open' => '1060.00', 'total_overdue' => '1060.00', 'credit_notes' => '0.00', 'net_total' => '1060.00'])
        ->and($beta['buckets'])->toBe(['current' => '0.00', '1_30' => '10.00', '31_60' => '1000.00', '61_90' => '20.00', 'over_90' => '30.00']);
});

it('puts every document in the bucket of its days past due, on the boundaries too', function (): void {
    $items = collect(apAging($this)['suppliers'])->flatMap(fn (array $supplier): array => $supplier['items'])->keyBy('document_number');

    expect($items['A-C1'])->toMatchArray(['bucket' => 'current', 'days_past_due' => 0, 'amount' => '100.00'])
        ->and($items['A-C2'])->toMatchArray(['bucket' => 'current', 'days_past_due' => 0])
        ->and($items['B-1'])->toMatchArray(['bucket' => '1_30', 'days_past_due' => 30])
        ->and($items['B-2'])->toMatchArray(['bucket' => '31_60', 'days_past_due' => 31])
        ->and($items['B-3'])->toMatchArray(['bucket' => '61_90', 'days_past_due' => 90])
        ->and($items['B-4'])->toMatchArray(['bucket' => 'over_90', 'days_past_due' => 91])
        ->and($items['A-NC'])->toMatchArray(['kind' => 'credit_note', 'amount' => '80.00', 'bucket' => '1_30'])
        ->and($items['A-O4'])->toMatchArray(['status' => 'open', 'on_hold' => false, 'due_date' => now()->subDays(120)->toDateString(), 'document_date' => now()->subDays(200)->toDateString()]);
});

it('ages as of another date', function (): void {
    $report = apAging($this, 'as_of='.now()->addDays(10)->toDateString());

    // 10 days later the invoice due in 5 days is 5 days late and the one due today 10 days late (the 30 days late one is now 40).
    expect($report['as_of'])->toBe(now()->addDays(10)->toDateString())
        ->and($report['summary']['buckets']['current'])->toBe('0.00')
        ->and($report['summary']['buckets']['1_30'])->toBe('350.00');
});

it('counts the disputed and blocked items as debt and reports them as held', function (): void {
    apSync($this);
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem(PassiveInvoice::withoutGlobalScopes()->where('invoice_number', 'A-O1')->firstOrFail())->id.'/dispute', ['reason' => 'Wrong amount'])->assertOk();

    $report = apAging($this);
    $item = collect($report['suppliers'][0]['items'])->firstWhere('document_number', 'A-O1');

    expect($report['summary']['held_total'])->toBe('200.00')
        ->and($report['summary']['total_open'])->toBe('2610.00')
        ->and($report['summary']['buckets']['1_30'])->toBe('210.00')
        ->and($report['suppliers'][0]['held_total'])->toBe('200.00')
        ->and($item)->toMatchArray(['status' => 'disputed', 'on_hold' => true]);
});

it('filters by supplier, leaves the detail out on request and keeps the currencies apart', function (): void {
    apInvoice($this, $this->supplier, 'A-CHF', '700.00', -5, ['currency' => 'CHF']);

    $beta = apAging($this, 'supplier_id='.$this->beta->id.'&detail=0');
    $chf = apAging($this, 'currency=chf');

    expect($beta['suppliers'])->toHaveCount(1)
        ->and($beta['suppliers'][0]['items'])->toBe([])
        ->and($beta['summary']['total_open'])->toBe('1060.00')
        ->and($chf['available_currencies'])->toBe(['CHF', 'EUR'])
        ->and($chf)->toMatchArray(['currency' => 'CHF'])
        ->and($chf['summary']['total_open'])->toBe('700.00')
        ->and(apAging($this)['summary']['total_open'])->toBe('2610.00');

    $this->getJson('/api/v1/accounts-payable/aging?currency=EURO')->assertUnprocessable()->assertJsonValidationErrors(['currency']);
});

it('stops counting an invoice once it is paid', function (): void {
    apSync($this);
    PassiveInvoice::withoutGlobalScopes()->where('invoice_number', 'A-O4')->firstOrFail()->forceFill(['total_paid' => '500.00', 'payment_status' => 'paid'])->save();

    $report = apAging($this);

    expect($report['summary']['buckets']['over_90'])->toBe('30.00')
        ->and($report['summary']['total_open'])->toBe('2110.00');
});

it('exports the aging to an Excel workbook with the labels of the requested locale', function (string $locale, string $title, string $summary, string $supplier): void {
    $response = $this->get("/api/v1/accounts-payable/aging/export/xlsx?locale={$locale}")->assertOk();
    $workbook = apWorkbook($response->getContent());

    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('payables-aging-'.now()->toDateString().'.xlsx')
        ->and(array_keys($workbook))->toHaveCount(2)
        ->and($workbook[$summary][0][0])->toBe($title)
        ->and($workbook[$summary][0][3])->toBe(now()->toDateString())
        ->and($workbook[$summary][1][0])->toBe($supplier)
        ->and($workbook[$summary])->toHaveCount(5);

    // One line per supplier: name, tax ID, the five buckets, open invoices, credit notes, net payable, held. Then the total.
    expect($workbook[$summary][2][0])->toBe('Fornitore Alfa SRL')
        ->and($workbook[$summary][2][2])->toEqual(150)
        ->and($workbook[$summary][2][6])->toEqual(500)
        ->and($workbook[$summary][2][7])->toEqual(1550)
        ->and($workbook[$summary][2][8])->toEqual(80)
        ->and($workbook[$summary][2][9])->toEqual(1470)
        ->and($workbook[$summary][4][7])->toEqual(2610);
})->with([
    'italian' => ['it', 'Aging fornitori', 'Riepilogo', 'Fornitore'],
    'german' => ['de', 'Kreditoren-Fälligkeitsanalyse', 'Übersicht', 'Lieferant'],
    'french' => ['fr', 'Balance âgée fournisseurs', 'Récapitulatif', 'Fournisseur'],
    'english' => ['en', 'Payables aging', 'Summary', 'Supplier'],
]);

it('lists every document in the detail sheet, the credit notes as negative amounts', function (): void {
    $workbook = apWorkbook($this->get('/api/v1/accounts-payable/aging/export/xlsx?locale=en')->assertOk()->getContent());
    $detail = collect($workbook['Detail'])->skip(1)->keyBy(fn (array $row): string => (string) $row[1]);

    expect($workbook['Detail'][0][1])->toBe('Document')
        ->and($detail)->toHaveCount(11)
        ->and($detail['A-NC'][7])->toEqual(-80)
        ->and($detail['A-NC'][2])->toBe('Credit note')
        ->and($detail['B-4'][7])->toEqual(30)
        ->and($detail['B-4'][6])->toBe('Over 90 days overdue');
});

it('exports the aging to a PDF', function (): void {
    $response = $this->get('/api/v1/accounts-payable/aging/export/pdf?locale=de')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('payables-aging-'.now()->toDateString().'.pdf')
        ->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();

    $this->getJson('/api/v1/accounts-payable/aging/export/pdf?locale=xx')->assertUnprocessable()->assertJsonValidationErrors(['locale']);
});
