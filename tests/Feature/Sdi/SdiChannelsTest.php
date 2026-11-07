<?php

declare(strict_types=1);

use App\Actions\Sdi\SendInvoiceToSdi;
use App\Contracts\Security\HostResolver;
use App\Exceptions\SdiProviderException;
use App\Models\Contact;
use App\Models\EiInboundLog;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\SDI\Contracts\ImapMailbox;
use Modules\SDI\Jobs\PollSdiChannelJob;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\PecMailboxFactory;
use Modules\SDI\Services\PecMailer;
use Modules\SDI\Services\SdiInboundProcessor;
use Symfony\Component\Mime\Email;
use Tests\Support\StaticHostResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi Srl',
        'legal_name' => 'Rossi Srl',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
        'pec_email' => 'rossi@pec.example.it',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);

    $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Bianchi SpA',
        'country' => 'IT',
        'vat_number' => '01234567897',
        'sdi_code' => '0000000',
        'pec' => 'bianchi@pec.example.it',
    ]);
    $this->contact->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);

    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function channelInvoice(array $attributes = [], float $rate = 22.0): Invoice
{
    $invoice = Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->contact->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => $rate,
        'total_gross' => 100 + $rate,
        'jurisdiction' => 'IT',
    ], $attributes));
    $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => $rate, 'line_total' => 100]);

    return $invoice;
}

function pecTenant(array $attributes = []): Tenant
{
    test()->tenant->update(array_merge([
        'sdi_channel' => 'pec',
        'sdi_pec_smtp_host' => 'smtps.pec.example.it',
        'sdi_pec_imap_host' => 'imaps.pec.example.it',
        'sdi_pec_username' => 'rossi@pec.example.it',
        'sdi_pec_password' => 'mailbox-password',
    ], $attributes));

    return test()->tenant->refresh();
}

function fakePecMailer(): object
{
    $mailer = new class extends PecMailer
    {
        /** @var list<Email> */
        public array $sent = [];

        public function send(Tenant $tenant, Email $email): void
        {
            $this->sent[] = $email;
        }
    };

    app()->instance(PecMailer::class, $mailer);

    return $mailer;
}

/**
 * @param  array<int, string>  $messages
 */
function fakeMailbox(array $messages): void
{
    app()->instance(PecMailboxFactory::class, new class($messages) extends PecMailboxFactory
    {
        public function __construct(private readonly array $messages) {}

        public function open(Tenant $tenant): ImapMailbox
        {
            return new class($this->messages) implements ImapMailbox
            {
                public function __construct(private readonly array $messages) {}

                public function uidsAfter(int $uid): array
                {
                    return array_values(array_filter(array_keys($this->messages), fn (int $found): bool => $found > $uid));
                }

                public function fetch(int $uid): string
                {
                    return $this->messages[$uid];
                }

                public function close(): void {}
            };
        }
    });
}

/**
 * A certified message: the envelope of the PEC provider around postacert.eml, which holds the SDI attachment.
 */
function pecMessage(string $from, string $fileName, string $content, array $headers = []): string
{
    $extra = implode('', array_map(fn (string $name, string $value): string => "{$name}: {$value}\r\n", array_keys($headers), $headers));
    $encoded = chunk_split(base64_encode($content));

    $inner = "From: {$from}\r\nContent-Type: multipart/mixed; boundary=\"inner\"\r\n\r\n"
        ."--inner\r\nContent-Type: text/plain\r\n\r\nInvio file\r\n"
        ."--inner\r\nContent-Type: application/xml; name=\"{$fileName}\"\r\nContent-Disposition: attachment; filename=\"{$fileName}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}\r\n--inner--\r\n";

    return "From: Per conto di: {$from} <posta-certificata@pec.example.it>\r\n{$extra}Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
        ."--outer\r\nContent-Type: text/plain\r\n\r\nMessaggio di posta certificata\r\n"
        ."--outer\r\nContent-Type: application/xml; name=\"daticert.xml\"\r\nContent-Disposition: attachment; filename=\"daticert.xml\"\r\n\r\n<postacert/>\r\n"
        ."--outer\r\nContent-Type: message/rfc822; name=\"postacert.eml\"\r\nContent-Disposition: attachment; filename=\"postacert.eml\"\r\n\r\n{$inner}\r\n--outer--\r\n";
}

function supplierInvoiceXml(string $number = 'F-77'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA>
      <Anagrafica><Denominazione>Fornitore Srl</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdFiscaleIVA>
    </DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento>
      <TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-03-01</Data><Numero>{$number}</Numero>
      <ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>
    </DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>100.00</ImponibileImporto><Imposta>22.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><DettaglioPagamento><DataScadenzaPagamento>2026-03-31</DataScadenzaPagamento><ImportoPagamento>122.00</ImportoPagamento></DettaglioPagamento></DatiPagamento>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

function deliveryReceipt(string $sentFile): string
{
    return "<?xml version=\"1.0\"?><ns3:RicevutaConsegna xmlns:ns3=\"http://www.fatturapa.gov.it/sdi/messaggi/v1.0\"><IdentificativoSdI>111</IdentificativoSdI><NomeFile>{$sentFile}</NomeFile></ns3:RicevutaConsegna>";
}

function rejectionReceipt(string $sentFile): string
{
    return "<?xml version=\"1.0\"?><ns3:NotificaScarto xmlns:ns3=\"http://www.fatturapa.gov.it/sdi/messaggi/v1.0\"><IdentificativoSdI>111</IdentificativoSdI><NomeFile>{$sentFile}</NomeFile><ListaErrori><Errore><Codice>00200</Codice><Descrizione>File non conforme</Descrizione></Errore></ListaErrori></ns3:NotificaScarto>";
}

// ------------------------------------------------------------ FatturaPA

test('the generated invoice complies with the official schema', function (array $attributes, float $rate): void {
    $this->tenant->update(['settings' => [...$this->tenant->settings, 'sdi_zero_rate_nature' => 'N2.2']]);

    $invoice = channelInvoice($attributes, $rate)->load(['tenant', 'contact', 'items']);

    expect(app(FatturaPaSchemaValidator::class)->errors(app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice, '0000A')))->toBe([]);
})->with([
    'standard' => [[], 22.0],
    'split payment' => [['split_payment' => true], 22.0],
    'reverse charge' => [['reverse_charge' => true], 22.0],
    'zero rate' => [[], 0.0],
    'withholding and stamp' => [['withholding_tax_enabled' => true, 'withholding_tax_percent' => 20, 'withholding_tax_amount' => 20, 'stamp_duty_applied' => true, 'stamp_duty_amount' => 2], 22.0],
]);

test('the schema validator reports a malformed invoice', function (): void {
    expect(app(FatturaPaSchemaValidator::class)->errors('<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12"/>'))->not->toBe([]);
});

test('the payment mode of the invoice and the transmission progressive reach the XML', function (): void {
    $invoice = channelInvoice(['payment_method' => 'MP08'])->load(['tenant', 'contact', 'items']);
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice, '0000A');

    expect($xml)->toContain('<ModalitaPagamento>MP08</ModalitaPagamento>')
        ->and($xml)->toContain('<ProgressivoInvio>0000A</ProgressivoInvio>');
});

// ------------------------------------------------------------ PEC: sending

test('the first file goes to sdi01 with the file as only content', function (): void {
    pecTenant();
    $mailer = fakePecMailer();

    $metadata = app(SendInvoiceToSdi::class)->execute(channelInvoice());

    $email = $mailer->sent[0];

    expect($email->getTo()[0]->getAddress())->toBe('sdi01@pec.fatturapa.it')
        ->and($email->getFrom()[0]->getAddress())->toBe('rossi@pec.example.it')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe('IT00811720580_00001.xml')
        ->and($email->getTextBody())->toBeNull()
        ->and($metadata->sdi_status)->toBe('sending')
        ->and($metadata->sdi_identifier)->toBe('IT00811720580_00001')
        ->and($this->tenant->refresh()->sdi_pec_first_sent_at)->not->toBeNull();
});

test('a second file waits for the address assigned by SDI', function (): void {
    pecTenant(['sdi_pec_first_sent_at' => now()]);
    fakePecMailer();

    app(SendInvoiceToSdi::class)->execute(channelInvoice());
})->throws(ValidationException::class);

test('the following files go to the assigned address', function (): void {
    pecTenant(['sdi_pec_first_sent_at' => now(), 'sdi_pec_address' => 'sdi24@pec.fatturapa.it']);
    $mailer = fakePecMailer();

    app(SendInvoiceToSdi::class)->execute(channelInvoice());

    expect($mailer->sent[0]->getTo()[0]->getAddress())->toBe('sdi24@pec.fatturapa.it');
});

test('invoices for public administrations cannot use the PEC channel', function (): void {
    pecTenant();
    fakePecMailer();
    $this->contact->update(['sdi_code' => 'UFABC1']);

    app(SendInvoiceToSdi::class)->execute(channelInvoice());
})->throws(ValidationException::class);

test('invented fiscal data never reaches a real channel', function (): void {
    pecTenant(['settings' => []]);
    $mailer = fakePecMailer();

    try {
        app(SendInvoiceToSdi::class)->execute(channelInvoice());
        $this->fail('The invoice should have been refused.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['invoice']))->toContain('street');
    }

    expect($mailer->sent)->toBe([]);
});

test('a rejected invoice is sent again under a new file name', function (): void {
    pecTenant(['sdi_pec_first_sent_at' => now(), 'sdi_pec_address' => 'sdi24@pec.fatturapa.it']);
    fakePecMailer();

    $invoice = channelInvoice();
    $first = app(SendInvoiceToSdi::class)->execute($invoice);
    $first->update(['sdi_status' => 'rejected']);

    $second = app(SendInvoiceToSdi::class)->execute($invoice->refresh());

    expect($second->file_name)->toBe('IT00811720580_00002.xml')
        ->and($second->sdi_status)->toBe('sending');
});

test('a tenant without a channel cannot transmit', function (): void {
    config(['services.sdi.default_channel' => null]);

    app(SendInvoiceToSdi::class)->execute(channelInvoice());
})->throws(SdiProviderException::class);

// ------------------------------------------------------------ PEC: receiving

test('a delivery receipt updates the invoice and teaches the assigned address', function (): void {
    pecTenant();
    fakePecMailer();
    $invoice = channelInvoice();
    app(SendInvoiceToSdi::class)->execute($invoice);

    fakeMailbox([7 => pecMessage('sdi24@pec.fatturapa.it', 'IT00811720580_00001_RC_001.xml', deliveryReceipt('IT00811720580_00001.xml'))]);

    $totals = app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($totals['notifications'])->toBe(1)
        ->and($invoice->refresh()->sdiMetadata->sdi_status)->toBe('delivered')
        ->and($this->tenant->refresh()->sdi_pec_address)->toBe('sdi24@pec.fatturapa.it')
        ->and($this->tenant->sdi_pec_last_uid)->toBe(7);
});

test('a rejection stores the SDI errors and a missed delivery stays a valid invoice', function (string $fileName, string $receipt, string $status): void {
    pecTenant(['sdi_pec_first_sent_at' => now(), 'sdi_pec_address' => 'sdi24@pec.fatturapa.it']);
    fakePecMailer();
    $invoice = channelInvoice();
    app(SendInvoiceToSdi::class)->execute($invoice);

    fakeMailbox([1 => pecMessage('sdi24@pec.fatturapa.it', $fileName, $receipt)]);
    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($invoice->refresh()->sdiMetadata->sdi_status)->toBe($status);
})->with([
    'scarto' => ['IT00811720580_00001_NS_001.xml', rejectionReceipt('IT00811720580_00001.xml'), 'rejected'],
    'mancata consegna' => ['IT00811720580_00001_MC_001.xml', deliveryReceipt('IT00811720580_00001.xml'), 'not_delivered'],
]);

test('a not delivered invoice cannot be sent twice', function (): void {
    pecTenant(['sdi_pec_first_sent_at' => now(), 'sdi_pec_address' => 'sdi24@pec.fatturapa.it']);
    fakePecMailer();
    $invoice = channelInvoice();
    app(SendInvoiceToSdi::class)->execute($invoice)->update(['sdi_status' => 'not_delivered']);

    app(SendInvoiceToSdi::class)->execute($invoice->refresh());
})->throws(ValidationException::class);

test('supplier invoices arriving on the mailbox enter the passive cycle once', function (): void {
    pecTenant();
    fakeMailbox([
        3 => pecMessage('sdi11@pec.fatturapa.it', 'IT01234567897_0ABCD.xml', supplierInvoiceXml()),
        4 => pecMessage('sdi11@pec.fatturapa.it', 'IT01234567897_0ABCD.xml', supplierInvoiceXml()),
    ]);

    $totals = app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($totals['invoices'])->toBe(1)
        ->and(PassiveInvoice::count())->toBe(1)
        ->and($this->tenant->refresh()->sdi_pec_address)->toBeNull()
        ->and($this->tenant->sdi_pec_last_uid)->toBe(4);
});

test('the delivery receipts of the PEC provider are not read as supplier invoices', function (): void {
    pecTenant();
    fakeMailbox([9 => pecMessage('sdi01@pec.fatturapa.it', 'IT00811720580_00001.xml', supplierInvoiceXml(), ['X-Ricevuta' => 'avvenuta-consegna'])]);

    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect(PassiveInvoice::count())->toBe(0)
        ->and($this->tenant->refresh()->sdi_pec_last_uid)->toBe(9);
});

test('ordinary mail is skipped and not read again', function (): void {
    pecTenant();
    fakeMailbox([5 => "From: amico@example.com\r\nContent-Type: text/plain\r\n\r\nCiao"]);

    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($this->tenant->refresh()->sdi_pec_last_uid)->toBe(5);
});

test('a file addressed to another company is logged and does not block the mailbox', function (): void {
    pecTenant();
    fakeMailbox([
        1 => pecMessage('sdi11@pec.fatturapa.it', 'IT01234567897_0BAD1.xml', str_replace('00811720580', '99999999999', supplierInvoiceXml())),
        2 => pecMessage('sdi11@pec.fatturapa.it', 'IT01234567897_0OK02.xml', supplierInvoiceXml('F-78')),
    ]);

    $totals = app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($totals)->toBe(['notifications' => 0, 'invoices' => 1, 'rejected' => 1])
        ->and(EiInboundLog::where('status', 'failed')->count())->toBe(1);
});

// ------------------------------------------------------------ API providers

test('OpenAPI receives the XML and its states become receipts', function (): void {
    $this->tenant->update(['sdi_channel' => 'openapi', 'sdi_environment' => 'sandbox', 'sdi_api_credentials' => ['token' => 'tok']]);

    Http::fake([
        'test.invoice.openapi.com/IT-invoices/abc' => Http::response(['data' => ['id' => 'abc', 'details' => ['sdi_status' => 'Delivered']]]),
        'test.invoice.openapi.com/IT-invoices?*' => Http::response(['data' => []]),
        'test.invoice.openapi.com/IT-invoices' => Http::response(['data' => ['id' => 'abc', 'state' => 'NEW']]),
    ]);

    $invoice = channelInvoice();
    $metadata = app(SendInvoiceToSdi::class)->execute($invoice);

    expect($metadata->sdi_identifier)->toBe('abc');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://test.invoice.openapi.com/IT-invoices'
        && $request->hasHeader('Authorization', 'Bearer tok')
        && $request->hasHeader('Content-Type', 'application/xml')
        && str_contains($request->body(), 'FatturaElettronica'));

    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($invoice->refresh()->sdiMetadata->sdi_status)->toBe('delivered');
});

test('OpenAPI supplier invoices are downloaded once', function (): void {
    $this->tenant->update(['sdi_channel' => 'openapi', 'sdi_api_credentials' => ['token' => 'tok']]);

    Http::fake([
        'invoice.openapi.com/IT-invoices?*' => Http::response(['data' => [['id' => 'sup1', 'details' => ['sdi_filename' => 'IT01234567897_0ABCD.xml']]]]),
        'invoice.openapi.com/IT-invoices/sup1' => Http::response(supplierInvoiceXml()),
    ]);

    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());
    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect(PassiveInvoice::count())->toBe(1);
    Http::assertSentCount(3);
});

test('A-Cube logs in once and maps its notifications', function (string $type, mixed $message, string $status): void {
    $this->tenant->update(['sdi_channel' => 'acube', 'sdi_sign_outgoing' => true, 'sdi_api_credentials' => ['email' => 'a@example.com', 'password' => 'pw']]);

    Http::fake([
        'common.api.acubeapi.com/login' => Http::response(['token' => 'jwt']),
        'api.acubeapi.com/invoices/uuid-1/notifications' => Http::response([['type' => $type, 'message' => $message]]),
        'api.acubeapi.com/invoices?*' => Http::response([]),
        'api.acubeapi.com/invoices' => Http::response(['uuid' => 'uuid-1'], 202),
    ]);

    $invoice = channelInvoice();
    app(SendInvoiceToSdi::class)->execute($invoice);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.acubeapi.com/invoices'
        && $request->hasHeader('X-SignInvoice', 'true')
        && $request->hasHeader('Authorization', 'Bearer jwt'));

    app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($invoice->refresh()->sdiMetadata->sdi_status)->toBe($status);
    Http::assertSentCount(4);
})->with([
    'delivered' => ['RC', null, 'delivered'],
    'rejected' => ['NS', ['lista_errori' => [['codice' => '00200']]], 'rejected'],
    'not delivered' => ['MC', null, 'not_delivered'],
    'accepted by the administration' => ['NE', ['esito_committente' => 'EC01'], 'accepted'],
    'refused by the administration' => ['NE', ['esito_committente' => 'EC02'], 'rejected'],
]);

test('Invoicetronic uses basic auth, idempotency and reads updates and received files', function (): void {
    $this->tenant->update(['sdi_channel' => 'invoicetronic', 'sdi_api_credentials' => ['api_key' => 'ik_test']]);

    Http::fake([
        'api.invoicetronic.com/v1/send/xml*' => Http::response(['id' => 42, 'identifier' => null], 201),
        'api.invoicetronic.com/v1/update*' => Http::response([['send_id' => 42, 'state' => 'Scartato', 'description' => 'Errori', 'errors' => [['code' => '00200']]]]),
        'api.invoicetronic.com/v1/receive*' => Http::response([['id' => 8, 'file_name' => 'IT01234567897_0ABCD.xml', 'encoding' => 'Base64', 'payload' => base64_encode(supplierInvoiceXml())]]),
    ]);

    $invoice = channelInvoice();
    app(SendInvoiceToSdi::class)->execute($invoice);

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/send/xml?validate=false&signature=Auto')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ik_test:'))
        && $request->hasHeader('Idempotency-Key', 'IT00811720580_00001.xml'));

    $totals = app(SdiInboundProcessor::class)->poll($this->tenant->refresh());

    expect($totals)->toBe(['notifications' => 1, 'invoices' => 1, 'rejected' => 0])
        ->and($invoice->refresh()->sdiMetadata->sdi_status)->toBe('rejected');
});

test('an API failure is logged on the invoice and not reported as sent', function (): void {
    $this->tenant->update(['sdi_channel' => 'openapi', 'sdi_api_credentials' => ['token' => 'tok']]);
    Http::fake(['*' => Http::response(['error' => 'denied'], 403)]);

    $invoice = channelInvoice();

    expect(fn () => app(SendInvoiceToSdi::class)->execute($invoice))->toThrow(SdiProviderException::class)
        ->and($invoice->refresh()->sdiMetadata->sdi_status)->toBe('failed');
});

test('a real channel refuses a file that does not pass the schema', function (): void {
    $this->tenant->update(['sdi_channel' => 'openapi', 'sdi_api_credentials' => ['token' => 'tok']]);
    Http::fake();

    $this->contact->update(['sdi_code' => 'X']);

    expect(fn () => app(SendInvoiceToSdi::class)->execute(channelInvoice()))->toThrow(SdiProviderException::class);
    Http::assertNothingSent();
});

// ------------------------------------------------------------ Webhooks

test('webhooks queue a read only with valid credentials of the provider', function (string $channel, callable $call, callable $forged): void {
    Queue::fake();
    $this->tenant->update(['sdi_channel' => $channel, 'sdi_webhook_secret' => 'a-long-webhook-secret-value']);
    app(TenantContext::class)->clear();

    $url = "/api/v1/webhooks/sdi/gateways/{$this->tenant->id}";

    $forged($this, $url)->assertUnauthorized();
    Queue::assertNothingPushed();

    $call($this, $url)->assertStatus(202);
    Queue::assertPushed(PollSdiChannelJob::class, fn (PollSdiChannelJob $job): bool => $job->tenantId === $this->tenant->id);
})->with([
    'openapi' => ['openapi',
        fn ($test, string $url) => $test->postJson("{$url}?token=a-long-webhook-secret-value"),
        fn ($test, string $url) => $test->postJson("{$url}?token=wrong"),
    ],
    'acube' => ['acube',
        fn ($test, string $url) => $test->withToken('a-long-webhook-secret-value')->postJson($url),
        fn ($test, string $url) => $test->withToken('wrong')->postJson($url),
    ],
    'invoicetronic' => ['invoicetronic',
        function ($test, string $url) {
            $body = '{"Id":1}';
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', "{$time}.{$body}", 'a-long-webhook-secret-value');

            return $test->call('POST', $url, [], [], [], ['HTTP_INVOICETRONIC_SIGNATURE' => "t={$time},v1={$signature}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
        },
        fn ($test, string $url) => $test->withHeader('Invoicetronic-Signature', 't='.now()->timestamp.',v1=forged')->postJson($url),
    ],
]);

test('a stale Invoicetronic signature is refused', function (): void {
    $this->tenant->update(['sdi_channel' => 'invoicetronic', 'sdi_webhook_secret' => 'a-long-webhook-secret-value']);
    $body = '{"Id":1}';
    $time = now()->subHour()->timestamp;
    $signature = hash_hmac('sha256', "{$time}.{$body}", 'a-long-webhook-secret-value');

    $this->call('POST', "/api/v1/webhooks/sdi/gateways/{$this->tenant->id}", [], [], [], ['HTTP_INVOICETRONIC_SIGNATURE' => "t={$time},v1={$signature}", 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertUnauthorized();
});

test('the scheduler command queues only tenants on a real channel', function (): void {
    Queue::fake();
    $this->tenant->update(['sdi_channel' => 'pec']);
    Tenant::create(['name' => 'Offline', 'legal_name' => 'Offline', 'sdi_channel' => 'local']);
    Tenant::create(['name' => 'None', 'legal_name' => 'None']);

    $this->artisan('sdi:poll')->assertSuccessful();

    Queue::assertPushed(PollSdiChannelJob::class, 1);
});

// ------------------------------------------------------------ Tenant settings

function channelApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('administrators configure the channel and secrets never come back', function (): void {
    $response = channelApi()->putJson('/api/v1/settings/sdi-channel', [
        'channel' => 'pec',
        'pec_email' => 'rossi@pec.example.it',
        'pec_smtp_host' => 'smtps.pec.example.it',
        'pec_imap_host' => 'imaps.pec.example.it',
        'pec_username' => 'rossi@pec.example.it',
        'pec_password' => 'mailbox-password',
        'zero_rate_nature' => 'N2.2',
        'ade_telematic_address_registered' => true,
        'ade_conservation_agreement_at' => '2026-11-06',
    ])->assertOk();

    expect($response->json('data.pec_password_set'))->toBeTrue()
        ->and($response->json('data.zero_rate_nature'))->toBe('N2.2')
        ->and($response->json('data.ade_telematic_address_registered'))->toBeTrue()
        ->and($response->getContent())->not->toContain('mailbox-password');

    $stored = $this->tenant->refresh();

    expect($stored->sdi_pec_password)->toBe('mailbox-password')
        ->and(Tenant::query()->toBase()->where('id', $stored->id)->value('sdi_pec_password'))->not->toBe('mailbox-password')
        ->and($stored->toArray())->not->toHaveKey('sdi_pec_password');
});

test('pec mail servers must be public hosts on mail ports', function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver(['mail.corp-intranet.com' => ['192.168.0.25']]));
    $pec = fn (array $values): array => $values + [
        'channel' => 'pec', 'pec_email' => 'rossi@pec.example.it', 'pec_smtp_host' => 'smtps.pec.example.it',
        'pec_imap_host' => 'imaps.pec.example.it', 'pec_username' => 'rossi@pec.example.it', 'pec_password' => 'pw',
    ];

    foreach (['127.0.0.1', 'localhost', 'mail.corp-intranet.com', '0x7f.1', 'metadata.google.internal'] as $host) {
        channelApi()->putJson('/api/v1/settings/sdi-channel', $pec(['pec_imap_host' => $host]))->assertUnprocessable()->assertJsonValidationErrors('pec_imap_host');
        channelApi()->putJson('/api/v1/settings/sdi-channel', $pec(['pec_smtp_host' => $host]))->assertUnprocessable()->assertJsonValidationErrors('pec_smtp_host');
    }

    channelApi()->putJson('/api/v1/settings/sdi-channel', $pec(['pec_imap_port' => 6379]))->assertUnprocessable()->assertJsonValidationErrors('pec_imap_port');
    channelApi()->putJson('/api/v1/settings/sdi-channel', $pec(['pec_smtp_port' => 22]))->assertUnprocessable()->assertJsonValidationErrors('pec_smtp_port');
    channelApi()->putJson('/api/v1/settings/sdi-channel', $pec(['pec_imap_port' => 993, 'pec_smtp_port' => 465]))->assertOk();
});

test('secrets left out keep their stored value and credentials are listed by name only', function (): void {
    channelApi()->putJson('/api/v1/settings/sdi-channel', ['channel' => 'acube', 'credentials' => ['email' => 'a@example.com', 'password' => 'pw']])->assertOk();

    $response = channelApi()->putJson('/api/v1/settings/sdi-channel', ['channel' => 'acube', 'environment' => 'sandbox'])->assertOk();

    expect($response->json('data.credentials_set'))->toBe(['email', 'password'])
        ->and($response->json('data.webhook_url'))->toContain($this->tenant->id)
        ->and($this->tenant->refresh()->sdi_api_credentials['password'])->toBe('pw');
});

test('members without a management role cannot touch the channel', function (): void {
    $this->user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);

    channelApi()->getJson('/api/v1/settings/sdi-channel')->assertForbidden();
    channelApi()->putJson('/api/v1/settings/sdi-channel', ['channel' => 'local'])->assertForbidden();
    channelApi()->postJson('/api/v1/settings/sdi-channel/poll')->assertForbidden();
});

test('an address that is not an SDI mailbox is refused', function (): void {
    channelApi()->putJson('/api/v1/settings/sdi-channel', ['channel' => 'local', 'pec_address' => 'someone@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('pec_address');
});
