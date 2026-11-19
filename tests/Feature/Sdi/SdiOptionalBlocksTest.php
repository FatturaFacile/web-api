<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\ItalianSdi\FatturaPaOptionalBlocks;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\SogeiCongruityValidator;

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
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $customerAttributes
 */
function optionalBlocksInvoice(array $invoiceAttributes = [], array $itemAttributes = [], array $customerAttributes = []): Invoice
{
    $tenant = test()->tenant;
    $customer = Contact::create(array_merge(['tenant_id' => $tenant->id, 'country' => 'IT', 'company_name' => 'Bianchi SpA', 'vat_number' => '01234567897', 'sdi_code' => 'ABC1234'], $customerAttributes));
    $customer->addresses()->create(['tenant_id' => $tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);

    $invoice = Invoice::create(array_merge([
        'tenant_id' => $tenant->id,
        'contact_id' => $customer->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => 22,
        'total_gross' => 122,
        'jurisdiction' => 'IT',
    ], $invoiceAttributes));
    $invoice->items()->create(array_merge(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100], $itemAttributes));

    return $invoice->load(['tenant', 'contact.addresses', 'items']);
}

/**
 * @return list<string>
 */
function codesOf(array $issues): array
{
    return array_column($issues, 'code');
}

it('emits every optional header and body block in schema order and still validates against the XSD', function (): void {
    $party = ['DatiAnagrafici' => ['IdFiscaleIVA' => ['IdPaese' => 'IT', 'IdCodice' => '01234567897'], 'Anagrafica' => ['Denominazione' => 'Rep Srl']]];
    $reference = ['RiferimentoNumeroLinea' => [1], 'IdDocumento' => 'ORD-1', 'Data' => '2026-02-01', 'NumItem' => '1', 'CodiceCommessaConvenzione' => 'COM1', 'CodiceCUP' => 'CUP123', 'CodiceCIG' => 'ABC1234567'];

    $invoice = optionalBlocksInvoice([
        'sdi_extra' => [
            'EsigibilitaIVA' => 'D',
            'PaymentRows' => [['Beneficiario' => 'Rossi Srl', 'IstitutoFinanziario' => 'Banca X', 'BIC' => 'BCITITMM', 'ABI' => '05428', 'CAB' => '11101', 'ScontoPagamentoAnticipato' => '1.00', 'DataLimitePagamentoAnticipato' => '2026-03-20', 'PenalitaPagamentiRitardati' => '2.00', 'DataDecorrenzaPenale' => '2026-04-01', 'CodicePagamento' => 'COD1']],
            'FatturaElettronicaHeader' => [
                'DatiTrasmissione' => ['ContattiTrasmittente' => ['Telefono' => '0212345678', 'Email' => 'tx@example.it']],
                'CedentePrestatore' => [
                    'DatiAnagrafici' => ['Anagrafica' => ['CodEORI' => 'IT00811720580']],
                    'StabileOrganizzazione' => ['Indirizzo' => 'Via Roma 3', 'CAP' => '00100', 'Comune' => 'Roma', 'Provincia' => 'RM', 'Nazione' => 'IT'],
                    'IscrizioneREA' => ['Ufficio' => 'MI', 'NumeroREA' => '123456', 'CapitaleSociale' => '10000.00', 'SocioUnico' => 'SM', 'StatoLiquidazione' => 'LN'],
                    'Contatti' => ['Telefono' => '0212345678', 'Email' => 'info@example.it'],
                    'RiferimentoAmministrazione' => 'AMM-1',
                ],
                'RappresentanteFiscale' => $party,
                'CessionarioCommittente' => ['StabileOrganizzazione' => ['Indirizzo' => 'Via Sede 1', 'CAP' => '00100', 'Comune' => 'Roma', 'Nazione' => 'IT']],
                'TerzoIntermediarioOSoggettoEmittente' => $party,
                'SoggettoEmittente' => 'TZ',
            ],
            'FatturaElettronicaBody' => [
                'DatiGenerali' => [
                    'DatiGeneraliDocumento' => ['ScontoMaggiorazione' => [['Tipo' => 'SC', 'Percentuale' => '5.00']], 'Arrotondamento' => '0.00', 'Causale' => ['Rif. contratto 42']],
                    'DatiOrdineAcquisto' => [$reference],
                    'DatiContratto' => [$reference],
                    'DatiConvenzione' => [$reference],
                    'DatiRicezione' => [$reference],
                    'DatiSAL' => [['RiferimentoFase' => 1]],
                    'DatiTrasporto' => ['DatiAnagraficiVettore' => $party['DatiAnagrafici'], 'MezzoTrasporto' => 'Corriere', 'NumeroColli' => 3, 'UnitaMisuraPeso' => 'kg', 'PesoLordo' => '12.50', 'DataOraConsegna' => '2026-03-05T10:00:00'],
                    'FatturaPrincipale' => ['NumeroFatturaPrincipale' => 'F-1', 'DataFatturaPrincipale' => '2026-01-01'],
                ],
            ],
        ],
    ], [
        'sdi_data' => [
            'TipoCessionePrestazione' => 'PR',
            'CodiceArticolo' => [['CodiceTipo' => 'EAN', 'CodiceValore' => '8001234567890']],
            'UnitaMisura' => 'pz',
            'DataInizioPeriodo' => '2026-01-01',
            'DataFinePeriodo' => '2026-01-31',
            'RiferimentoAmministrazione' => 'RA-1',
            'AltriDatiGestionali' => [['TipoDato' => 'KEY', 'RiferimentoTesto' => 'value']],
        ],
    ]);

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice);

    expect(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain('<IscrizioneREA>')->toContain('<RappresentanteFiscale>')->toContain('<StabileOrganizzazione>')
        ->toContain('<ContattiTrasmittente>')->toContain('<DatiSAL>')->toContain('<DatiTrasporto>')->toContain('<FatturaPrincipale>')
        ->toContain('<DatiConvenzione>')->toContain('<DatiRicezione>')->toContain('<CodiceCIG>ABC1234567</CodiceCIG>')
        ->toContain('<CodiceArticolo>')->toContain('<UnitaMisura>pz</UnitaMisura>')->toContain('<ScontoPagamentoAnticipato>1.00</ScontoPagamentoAnticipato>')
        ->toContain('<EsigibilitaIVA>D</EsigibilitaIVA>')->toContain('<SoggettoEmittente>TZ</SoggettoEmittente>');
});

it('states the line discount so that PrezzoTotale passes the congruity check (00423)', function (): void {
    $invoice = optionalBlocksInvoice(
        ['total_net' => 90, 'total_tax' => 19.8, 'total_gross' => 109.8],
        ['discount_percentage' => 10, 'line_total' => 90],
    );

    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice);
    $result = (new SogeiCongruityValidator)->validate($xml);

    expect($xml)->toContain('<Percentuale>10.00</Percentuale>')
        ->and(app(FatturaPaSchemaValidator::class)->errors($xml))->toBe([])
        ->and(codesOf($result->errors))->not->toContain('00423');
});

it('splits a long wording over several Causale elements of at most 200 characters', function (): void {
    $chunks = (new FatturaPaOptionalBlocks)->chunkCausale(str_repeat('a', 450));

    expect($chunks)->toHaveCount(3)->and(mb_strlen($chunks[0]))->toBe(200)->and(mb_strlen($chunks[2]))->toBe(50);
});

it('refuses an element the FatturaPA schema does not allow', function (): void {
    $invoice = optionalBlocksInvoice(['sdi_extra' => ['FatturaElettronicaHeader' => ['CedentePrestatore' => ['NotAnElement' => 'x']]]]);

    app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice);
})->throws(InvalidArgumentException::class, 'NotAnElement');

it('validates a Codice Fiscale of 11 digits like a Partita IVA', function (): void {
    $builder = app(FatturaPaBuilderService::class);

    expect($builder->isValidCodiceFiscale('00811720580'))->toBeTrue()
        ->and($builder->isValidCodiceFiscale('00811720581'))->toBeFalse();
});

it('catches an invalid transmitter code (00305) and a counterparty without any fiscal identity (00312)', function (): void {
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(optionalBlocksInvoice());

    $badTransmitter = preg_replace('#(<IdTrasmittente>.*?<IdCodice>)00811720580#s', '${1}00811720581', $xml, 1);
    $noIdentity = preg_replace('#(<CessionarioCommittente>\s*<DatiAnagrafici>)\s*<IdFiscaleIVA>.*?</IdFiscaleIVA>#s', '$1', $xml, 1);

    expect(codesOf((new SogeiCongruityValidator)->validate((string) $badTransmitter)->errors))->toContain('00305')
        ->and(codesOf((new SogeiCongruityValidator)->validate((string) $noIdentity)->errors))->toContain('00312');
});

it('warns when ImportoTotaleDocumento does not match taxable amounts and tax (00419)', function (): void {
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml(optionalBlocksInvoice());
    $wrong = preg_replace('#<ImportoTotaleDocumento>[^<]+#', '<ImportoTotaleDocumento>999.00', $xml, 1);

    expect(codesOf((new SogeiCongruityValidator)->validate($xml)->warnings))->not->toContain('00419')
        ->and(codesOf((new SogeiCongruityValidator)->validate((string) $wrong)->warnings))->toContain('00419');
});

it('warns that a public administration invoice should carry a CIG', function (): void {
    $invoice = optionalBlocksInvoice([], [], ['company_name' => 'Comune di Roma', 'vat_number' => '02438750586', 'sdi_code' => 'UFABCD']);
    $xml = app(FatturaPaBuilderService::class)->buildFatturaPaXml($invoice);

    expect(codesOf((new SogeiCongruityValidator)->validate($xml)->warnings))->toContain('PA_CIG_EXPECTED');
});
