<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Exceptions\QrBillGenerationException;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pdf\DocumentPdfService;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\SwissQrBill\SwissQrBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const QR_IBAN = 'CH4431999123000889012';
const STANDARD_IBAN = 'CH9300762011623852957';
const QR_REFERENCE = '210000000003139471430009017';
const SCOR_REFERENCE = 'RF18 5390 0754 7034';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Switzerland AG',
        'legal_name' => 'FatturaFacile Switzerland AG',
        'vat_number' => 'CHE-123.456.789',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'iban' => STANDARD_IBAN,
        'qr_iban' => QR_IBAN,
        'settings' => ['preferences' => ['company' => ['street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '6900', 'city' => 'Lugano', 'country' => 'CH']]],
    ]);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Café Müller Zürich AG', 'country' => 'CH', 'email' => 'customer@example.com']);
    $this->contact->addresses()->create(['type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '12', 'postal_code' => '8001', 'city' => 'Zürich', 'state_canton' => 'ZH', 'country' => 'CH']);

    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => 'ch_qr_bill', 'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'CHF', 'total_net' => 1803.75, 'total_tax' => 146.0, 'total_gross' => 1949.75, 'jurisdiction' => 'CH',
    ]);
    $this->invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1803.75, 'tax_rate' => 8.1, 'line_total' => 1803.75]);
});

function qrService(): SwissQrBillService
{
    return app(SwissQrBillService::class);
}

/**
 * @return array<int, string> the lines of an SPC payload
 */
function spcLines(string $payload): array
{
    return preg_split('/\R/', $payload);
}

function qrData(array $attributes): void
{
    InvoiceQrBillData::updateOrCreate(['invoice_id' => test()->invoice->id], array_merge([
        'qr_iban' => QR_IBAN, 'scor_reference' => '', 'creditor_info' => [], 'debtor_info' => [],
    ], $attributes));
    test()->invoice->unsetRelation('qrBillData');
}

function setTenant(array $attributes): void
{
    test()->tenant->update($attributes);
    test()->invoice->unsetRelation('tenant');
    test()->invoice->setRelation('tenant', test()->tenant->refresh());
}

function qrFailure(callable $callback): QrBillGenerationException
{
    try {
        $callback();
    } catch (QrBillGenerationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a QrBillGenerationException.');
}

function capturingGenerator(): object
{
    return new class implements PdfGenerator
    {
        /** @var array<int, array{view: string, data: array<string, mixed>}> */
        public array $calls = [];

        public function render(string $view, array $data = []): string
        {
            $this->calls[] = ['view' => $view, 'data' => $data];

            return '%PDF-fake';
        }
    };
}

// ------------------------------------------------------------ QR-IBAN + QR reference

test('a QR-IBAN invoice gets a derived 27-digit QR reference with a valid modulo 10 check digit', function (): void {
    $bill = qrService()->generate($this->invoice);

    expect($bill->referenceType)->toBe('QRR')
        ->and($bill->iban)->toBe(QR_IBAN)
        ->and($bill->reference)->toMatch('/^\d{27}$/')
        ->and($bill->reference)->toBe('000000000000000000202600013')
        ->and(app(QrBillGeneratorService::class)->validateScorReference($bill->reference))->toBeTrue()
        ->and($bill->formattedReference)->toBe('00 00000 00000 00000 02026 00013');

    $lines = spcLines($bill->payload);
    expect($lines[0])->toBe('SPC')
        ->and($lines[1])->toBe('0200')
        ->and($lines[2])->toBe('1')
        ->and($lines[3])->toBe(QR_IBAN)
        ->and($lines[5])->toBe('FatturaFacile Switzerland AG')
        ->and($lines[18])->toBe('1949.75')
        ->and($lines[19])->toBe('CHF')
        ->and($lines[27])->toBe('QRR')
        ->and($lines[28])->toBe($bill->reference)
        ->and($lines[29])->toBe('Invoice INV-2026-0001');
});

test('a stored valid QR reference is used as is and printed in five digit blocks', function (): void {
    qrData(['scor_reference' => QR_REFERENCE]);

    $bill = qrService()->generate($this->invoice);

    expect($bill->referenceType)->toBe('QRR')->and($bill->reference)->toBe(QR_REFERENCE)->and($bill->formattedReference)->toBe('21 00000 00003 13947 14300 09017');
});

test('the QR code is returned as SPC payload, SVG and PNG data URI', function (): void {
    $bill = qrService()->generate($this->invoice);

    expect($bill->svg)->toContain('<svg')
        ->and($bill->pngDataUri)->toStartWith('data:image/png;base64,')
        ->and(substr(base64_decode(substr($bill->pngDataUri, strlen('data:image/png;base64,'))), 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($bill->formattedIban)->toBe('CH44 3199 9123 0008 8901 2')
        ->and($bill->formattedAmount)->toBe('1 949.75')
        ->and($bill->creditorLines)->toBe(['FatturaFacile Switzerland AG', 'Bahnhofstrasse 1', '6900 Lugano'])
        ->and($bill->debtorLines)->toBe(['Café Müller Zürich AG', 'Bahnhofstrasse 12', '8001 Zürich']);
});

test('the outstanding balance is billed after partial payments', function (): void {
    $this->invoice->update(['total_paid' => 949.75]);

    $bill = qrService()->generate($this->invoice->refresh());

    expect($bill->amount)->toBe(1000.0)->and(spcLines($bill->payload)[18])->toBe('1000.00');
});

test('EUR invoices are supported', function (): void {
    $this->invoice->update(['currency' => 'EUR']);

    expect(qrService()->generate($this->invoice->refresh())->currency)->toBe('EUR');
});

// ------------------------------------------------------------ Standard IBAN + SCOR / NON

test('a stored ISO 11649 creditor reference is combined with the standard IBAN', function (): void {
    qrData(['scor_reference' => SCOR_REFERENCE]); // tenant has both a QR-IBAN and a standard IBAN

    $bill = qrService()->generate($this->invoice);

    expect($bill->referenceType)->toBe('SCOR')
        ->and($bill->iban)->toBe(STANDARD_IBAN)
        ->and($bill->reference)->toBe('RF18539007547034')
        ->and($bill->formattedReference)->toBe('RF18 5390 0754 7034');

    $lines = spcLines($bill->payload);
    expect($lines[3])->toBe(STANDARD_IBAN)->and($lines[27])->toBe('SCOR')->and($lines[28])->toBe('RF18539007547034');
});

test('a standard IBAN without reference produces a NON bill that still names the invoice', function (): void {
    setTenant(['qr_iban' => null]);

    $bill = qrService()->generate($this->invoice);

    expect($bill->referenceType)->toBe('NON')
        ->and($bill->iban)->toBe(STANDARD_IBAN)
        ->and($bill->reference)->toBeNull()
        ->and($bill->formattedReference)->toBeNull()
        ->and($bill->message)->toBe('Invoice INV-2026-0001');

    $lines = spcLines($bill->payload);
    expect($lines[27])->toBe('NON')->and($lines[28])->toBe('')->and($lines[29])->toBe('Invoice INV-2026-0001');
});

// ------------------------------------------------------------ Address and text normalisation

test('combined address lines are parsed into a structured address', function (): void {
    setTenant(['settings' => ['preferences' => ['company' => ['address_line_1' => 'Via Nassa 10a', 'address_line_2' => '6900 Lugano', 'country' => 'CH']]]]);

    $bill = qrService()->generate($this->invoice);
    $lines = spcLines($bill->payload);

    expect($lines[4])->toBe('S')->and($lines[6])->toBe('Via Nassa')->and($lines[7])->toBe('10a')->and($lines[8])->toBe('6900')->and($lines[9])->toBe('Lugano');
});

test('typographic and unsupported characters are normalised and fields are truncated', function (): void {
    setTenant(['legal_name' => 'Società — “Fattura” Facile '.str_repeat('X', 80)]);
    $this->contact->update(['company_name' => 'Жан SA']);

    $bill = qrService()->generate($this->invoice->refresh());
    $lines = spcLines($bill->payload);

    expect($lines[5])->toStartWith('Società - "Fattura" Facile XXX')->and(mb_strlen($lines[5]))->toBe(70)
        ->and($lines[21])->toBe('Zan SA');
});

test('the debtor is left out when the customer has no complete billing address', function (): void {
    $this->contact->addresses()->delete();

    $bill = qrService()->generate($this->invoice->refresh());

    expect($bill->debtorLines)->toBeNull()->and(spcLines($bill->payload)[20])->toBe('');
});

// ------------------------------------------------------------ Errors

test('invalid or missing data raises a typed exception with a machine readable reason', function (string $reason, Closure $arrange): void {
    $arrange();

    $failure = qrFailure(fn () => qrService()->generate($this->invoice->refresh()));

    expect($failure->reason)->toBe($reason)->and($failure->getMessage())->toBe(__("qr_bill.errors.{$reason}"));
})->with([
    'missing creditor address' => ['missing_creditor_address', fn () => setTenant(['settings' => []])],
    'incomplete creditor address' => ['missing_creditor_address', fn () => setTenant(['settings' => ['preferences' => ['company' => ['street' => 'Bahnhofstrasse']]]])],
    'no IBAN configured' => ['missing_creditor_iban', fn () => setTenant(['iban' => null, 'qr_iban' => null])],
    'non Swiss IBAN' => ['invalid_creditor_iban', fn () => setTenant(['iban' => 'IT60X0542811101000000123456', 'qr_iban' => null])],
    'unsupported currency' => ['invalid_currency', fn () => test()->invoice->update(['currency' => 'USD'])],
    'nothing to pay' => ['invalid_amount', fn () => test()->invoice->update(['total_paid' => 1949.75])],
    'QR reference without QR-IBAN' => ['reference_mismatch', function (): void {
        setTenant(['qr_iban' => null]);
        qrData(['qr_iban' => STANDARD_IBAN, 'scor_reference' => QR_REFERENCE]); // the column is NOT NULL; here it holds a standard IBAN
    }],
    'creditor reference without standard IBAN' => ['reference_mismatch', function (): void {
        setTenant(['iban' => null]);
        qrData(['scor_reference' => SCOR_REFERENCE]);
    }],
    'wrong modulo 10 check digit' => ['invalid_reference', fn () => qrData(['scor_reference' => '210000000003139471430009018'])],
    'garbage reference' => ['invalid_reference', fn () => qrData(['scor_reference' => 'ABC'])],
]);

test('a creditor reference with wrong ISO 11649 check digits fails the SIX validation and lists the violations', function (): void {
    qrData(['scor_reference' => 'RF00 5390 0754 7034']);

    $failure = qrFailure(fn () => qrService()->generate($this->invoice->refresh()));

    expect($failure->reason)->toBe('validation_failed')->and($failure->violations)->not->toBeEmpty();
});

// ------------------------------------------------------------ PDF integration

test('the invoice PDF template prints the QR image with receipt and payment part', function (): void {
    $fake = capturingGenerator();
    (new DocumentPdfService($fake, qrService()))->render($this->invoice);

    $call = $fake->calls[0];
    $html = view($call['view'], $call['data'])->render();

    expect($call['view'])->toBe('documents.ch_qr_bill')
        ->and($call['data']['qr']['image'])->toStartWith('data:image/png;base64,')
        ->and($call['data']['qr_error'])->toBeNull()
        ->and($html)->toContain('Receipt')->toContain('Payment part')->toContain('Acceptance point')->toContain('Account / Payable to')
        ->toContain('CH44 3199 9123 0008 8901 2')->toContain('00 00000 00000 00000 02026 00013')->toContain('Additional information')
        ->toContain('Payable by')->toContain('Café Müller Zürich AG')->toContain('data:image/png;base64,')->toContain('CHF')->toContain('1 949.75')
        ->toContain('Bahnhofstrasse 1')->not->toContain('could not be generated');
});

test('a bill without debtor shows the blank payable-by box', function (): void {
    $this->contact->addresses()->delete();

    $fake = capturingGenerator();
    (new DocumentPdfService($fake, qrService()))->render($this->invoice->refresh());

    expect(view($fake->calls[0]['view'], $fake->calls[0]['data'])->render())->toContain('Payable by (name/address)');
});

test('when the QR-bill cannot be generated the PDF still renders, logs the reason and shows a notice', function (): void {
    Log::spy();
    setTenant(['settings' => []]);

    $fake = capturingGenerator();
    $rendered = (new DocumentPdfService($fake, qrService()))->render($this->invoice->refresh());

    expect($rendered->filename)->toBe('Invoice-INV-2026-0001.pdf')
        ->and($fake->calls[0]['data']['qr'])->toBeNull()
        ->and($fake->calls[0]['data']['qr_error'])->toBe('missing_creditor_address')
        ->and(view($fake->calls[0]['view'], $fake->calls[0]['data'])->render())->toContain('could not be generated')->not->toContain('Payment part');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'missing_creditor_address' && $context['number'] === 'INV-2026-0001');
});

test('a fully paid QR invoice renders without a payment slip and without a warning', function (): void {
    Log::spy();
    $this->invoice->update(['total_paid' => 1949.75, 'payment_status' => 'paid']);

    $fake = capturingGenerator();
    (new DocumentPdfService($fake, qrService()))->render($this->invoice->refresh());

    expect($fake->calls[0]['data']['qr'])->toBeNull()->and($fake->calls[0]['data']['qr_error'])->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

test('standard invoices never touch the QR-bill service', function (): void {
    $standard = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-2026-0002',
        'date' => '2026-03-01', 'currency' => 'CHF', 'total_gross' => 10, 'jurisdiction' => 'CH',
    ]);
    $fake = capturingGenerator();

    (new DocumentPdfService($fake, qrService()))->render($standard);

    expect($fake->calls[0]['view'])->toBe('documents.invoice')->and($fake->calls[0]['data']['qr'])->toBeNull();
});

test('the real engine produces a PDF with the embedded QR code', function (): void {
    $withQr = pdfBytes($this->invoice);

    setTenant(['settings' => []]);
    $withoutQr = pdfBytes($this->invoice->refresh());

    expect(substr($withQr, 0, 5))->toBe('%PDF-')->and(substr($withoutQr, 0, 5))->toBe('%PDF-')
        ->and(strlen($withQr))->toBeGreaterThan(strlen($withoutQr) + 5000); // the embedded 543px QR PNG makes the document noticeably larger
});

function pdfBytes(Invoice $invoice): string
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id)->get("/api/v1/documents/invoice/{$invoice->id}/pdf")->assertOk()->getContent();
}

// ------------------------------------------------------------ API

test('the QR-bill endpoint returns the payload and images, or a problem document listing the failure', function (): void {
    $api = $this->withHeader('X-Tenant-ID', $this->tenant->id);

    $ok = $api->getJson("/api/v1/sales/invoices/{$this->invoice->id}/qr-bill")->assertOk();
    expect($ok->json('data.payload'))->toStartWith("SPC\n0200")
        ->and($ok->json('data.reference_type'))->toBe('QRR')
        ->and($ok->json('data.svg'))->toContain('<svg')
        ->and($ok->json('data.png'))->toStartWith('data:image/png;base64,')
        ->and($ok->json('data.amount'))->toEqual(1949.75);

    qrData(['scor_reference' => 'RF00 5390 0754 7034']);
    $api->getJson("/api/v1/sales/invoices/{$this->invoice->id}/qr-bill")
        ->assertUnprocessable()->assertJsonPath('title', 'Swiss QR-bill unavailable')->assertJsonStructure(['invalid_params']);

    $api->getJson('/api/v1/sales/invoices/'.Str::uuid().'/qr-bill')->assertNotFound();
});

test('the issuer address is stored through the company preferences and validated', function (): void {
    $api = $this->withHeader('X-Tenant-ID', $this->tenant->id);

    $api->putJson('/api/v1/settings/preferences/company', ['street' => 'Via Nassa', 'house_number' => '10', 'postal_code' => '6900', 'city' => 'Lugano', 'country' => 'CH'])
        ->assertOk()->assertJsonPath('data.city', 'Lugano');
    $api->putJson('/api/v1/settings/preferences/company', ['country' => 'ch'])->assertUnprocessable()->assertJsonValidationErrors('country');
    $api->putJson('/api/v1/settings/preferences/company', ['city' => str_repeat('x', 36)])->assertUnprocessable()->assertJsonValidationErrors('city');

    $this->tenant->refresh();
    expect($this->tenant->settings['preferences']['company']['street'])->toBe('Via Nassa');
});
