<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\AssessmentArchive;
use Modules\GeneralLedger\Models\ChartOfAccount;

function customsTenant(string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Spedizioni '.Str::random(6),
        'legal_name' => 'Spedizioni SA',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);
}

/**
 * @param  list<string>|null  $permissions
 */
function customsMember(Tenant $tenant, string $role = 'customs', ?array $permissions = null): User
{
    $user = User::create([
        'name' => ucfirst($role).' '.Str::random(4),
        'email' => Str::random(12).'@example.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => $role,
        'permissions' => $permissions === null ? null : json_encode($permissions),
    ]);

    return $user;
}

/**
 * Requests as a user, in a tenant.
 */
function customsApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function customsContact(Tenant $tenant, string $name = 'Importatrice SA', string $country = 'CH'): Contact
{
    app(TenantContext::class)->setTenant($tenant);

    $contact = new Contact;
    $contact->tenant_id = $tenant->id;
    $contact->fill(['type' => 'company', 'company_name' => $name, 'country' => $country]);
    $contact->save();

    $address = new ContactAddress;
    $address->fill(['contact_id' => $contact->id, 'type' => 'billing', 'street' => 'Via Nassa', 'house_number' => '1', 'postal_code' => '6900', 'city' => 'Lugano', 'country' => $country]);
    $address->save();

    return $contact;
}

function customsSettings(Tenant $tenant, array $overrides = []): CustomsSettings
{
    app(TenantContext::class)->setTenant($tenant);

    $settings = CustomsSettings::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
    $settings->tenant_id = $tenant->id;
    $settings->fill([
        'channel' => 'local',
        'trader_identification_number' => 'CHE123456789',
        'declarant_number' => 123456,
        'declarant_name' => 'Spedizioni SA',
        'declarant_street' => 'Via Dogana 2',
        'declarant_postal_code' => '6830',
        'declarant_city' => 'Chiasso',
        'declarant_country' => 'CH',
        'customs_account' => '10000',
        'vat_account' => '10000',
        'vat_number' => '123456',
        'receipt_role' => 0,
        ...$overrides,
    ]);
    $settings->save();

    return $settings;
}

/**
 * A declaration filled in as the e-dec schema wants it. The numeric codes are examples from the schema documentation.
 *
 * @return array<string, mixed>
 */
function customsImportPayload(Contact $consignee, array $overrides = []): array
{
    return [
        'declaration_type' => 'import',
        'consignee_contact_id' => $consignee->id,
        'clearance_location' => 1,
        'declaration_time' => 1,
        'declaration_kind' => 1,
        'language' => 'it',
        'dispatch_country' => 'IT',
        'transport_mode' => 3,
        'transportation_type' => 2,
        'transportation_country' => 'IT',
        'transportation_number' => 'TI-12345',
        'incoterms' => 'DAP',
        'invoice_currency_type' => 1,
        'items' => [
            customsItemPayload(),
            customsItemPayload(['description' => 'Vino rosso', 'commodity_code' => '2204.2100', 'gross_mass' => '25.500', 'net_mass' => '20.250', 'statistical_value' => '300.50', 'vat_value' => '320.40', 'package_quantity' => 3]),
        ],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function customsItemPayload(array $overrides = []): array
{
    return [
        'description' => 'Acqua minerale',
        'commodity_code' => '2201.1000',
        'statistical_code' => 911,
        'gross_mass' => '100.000',
        'net_mass' => '90.000',
        'permit_obligation' => 0,
        'non_customs_law_obligation' => 0,
        'customs_clearance_type' => 1,
        'commercial_good' => 1,
        'statistical_value' => '1000.00',
        'vat_value' => '1100.00',
        'vat_code' => 1,
        'origin_country' => 'IT',
        'packaging_type' => 'CT',
        'package_quantity' => 10,
        ...$overrides,
    ];
}

function customsDeclaration(Tenant $tenant, ?User $user = null): CustomsDeclaration
{
    customsApi($user ?? customsMember($tenant), $tenant);
    customsSettings($tenant);
    $response = test()->postJson('/api/v1/customs/declarations', customsImportPayload(customsContact($tenant)))->assertCreated();

    return CustomsDeclaration::withoutGlobalScopes()->findOrFail($response->json('data.id'));
}

function customsOfficialFixture(string $name = 'edecReceiptResponse_taxationDecisionVATImport.xml'): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
}

/**
 * The official BAZG sample of a VAT decision, with another identity. Its goods items add up to the given VAT.
 */
function customsVatDecisionXml(string $mrn = '26CHEI000000000001', int $version = 1, string $date = '2026-10-15', string $vat = '17.30'): string
{
    $xml = customsOfficialFixture();
    $xml = str_replace('15CHEI000005571286', $mrn, $xml);
    $xml = (string) preg_replace('#<documentVersion>\d+</documentVersion>#', "<documentVersion>{$version}</documentVersion>", $xml);
    $xml = (string) preg_replace('#<documentDate>[^<]+</documentDate>#', "<documentDate>{$date}</documentDate>", $xml);

    return (string) preg_replace('#(<VATNumber>[^<]*</VATNumber>\s*<totalAmount>)[^<]+#', '${1}'.$vat, $xml);
}

/**
 * The same sample turned into a duty decision.
 */
function customsDutyDecisionXml(string $mrn = '26CHEI000000000001', int $version = 1, string $date = '2026-10-15', string $duty = '250.00', string $type = 'taxationDecisionCustomsDuties'): string
{
    $xml = str_replace('taxationDecisionVAT', $type, customsVatDecisionXml($mrn, $version, $date));

    return (string) preg_replace(
        '#<valuationSummary>.*?</valuationSummary>#s',
        "<valuationSummary><dutyAndAdditionalTaxes><customsAccount><accountType>1</accountType><accountNumber>10000</accountNumber></customsAccount><detailAmount><type>Zoll</type><amount>{$duty}</amount></detailAmount><totalAmount>{$duty}</totalAmount></dutyAndAdditionalTaxes></valuationSummary>",
        $xml,
    );
}

function customsArchive(Tenant $tenant, string $xml, ?string $envelope = null, ?string $pdf = null, string $gateway = 'upload'): CustomsAssessmentDecision
{
    app(TenantContext::class)->setTenant($tenant);

    return app(AssessmentArchive::class)->store($tenant, new RetrievedAssessment($xml, $envelope, $pdf), $gateway)['decision'];
}

function customsLedgerAccount(Tenant $tenant, string $code, string $type): string
{
    app(TenantContext::class)->setTenant($tenant);

    return (string) ChartOfAccount::create([
        'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type,
        'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit', 'currency' => $tenant->default_currency,
    ])->id;
}

/**
 * A test certification authority and a leaf certificate.
 *
 * @return array{anchor: string, key: OpenSSLAsymmetricKey, certificate: string}
 */
function customsTestPki(string $validFor = '+1 year'): array
{
    $file = tempnam(sys_get_temp_dir(), 'customs-openssl');
    file_put_contents($file, "[req]\ndistinguished_name = dn\n[dn]\n");
    $config = ['config' => $file, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $caKey = openssl_pkey_new($config);
    $caRequest = openssl_csr_new(['commonName' => 'Test Root CA'], $caKey, $config);
    $caCertificate = openssl_csr_sign($caRequest, null, $caKey, 3650, $config, 1);

    $leafKey = openssl_pkey_new($config);
    $leafRequest = openssl_csr_new(['commonName' => 'Test e-dec'], $leafKey, $config);
    $leafCertificate = openssl_csr_sign($leafRequest, $caCertificate, $caKey, 365, $config, 2);

    openssl_x509_export($caCertificate, $anchorPem);
    openssl_x509_export($leafCertificate, $leafPem);

    $anchor = tempnam(sys_get_temp_dir(), 'customs-anchor');
    file_put_contents($anchor, $anchorPem);

    return ['anchor' => $anchor, 'key' => $leafKey, 'certificate' => $leafPem];
}

/**
 * A SOAP message signed the way the BAZG service contract shows it: exclusive C14N, BinarySecurityToken, signed Body.
 *
 * @param  array{key: OpenSSLAsymmetricKey, certificate: string}  $pki
 */
function customsSignedEnvelope(string $decisionXml, array $pki, string $created = 'now'): string
{
    $ds = 'http://www.w3.org/2000/09/xmldsig#';
    $wsse = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    $wsu = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    $body = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $decisionXml);
    $token = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pki['certificate']);
    $moment = date('Y-m-d\TH:i:s\Z', strtotime($created));

    $envelope = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header>'
        ."<wsse:Security xmlns:wsse=\"{$wsse}\"><wsse:BinarySecurityToken xmlns:wsu=\"{$wsu}\" wsu:Id=\"CertId-1\">{$token}</wsse:BinarySecurityToken>"
        ."<ds:Signature xmlns:ds=\"{$ds}\"><ds:SignedInfo><ds:CanonicalizationMethod Algorithm=\"http://www.w3.org/2001/10/xml-exc-c14n#\"></ds:CanonicalizationMethod>"
        .'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"></ds:SignatureMethod>'
        .'<ds:Reference URI="#id-body"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></ds:Transform></ds:Transforms>'
        .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"></ds:DigestMethod><ds:DigestValue>DIGEST</ds:DigestValue></ds:Reference>'
        .'</ds:SignedInfo><ds:SignatureValue>SIGNATURE</ds:SignatureValue><ds:KeyInfo><wsse:SecurityTokenReference><wsse:Reference URI="#CertId-1"></wsse:Reference></wsse:SecurityTokenReference></ds:KeyInfo></ds:Signature>'
        ."<wsu:Timestamp xmlns:wsu=\"{$wsu}\" wsu:Id=\"id-ts\"><wsu:Created>{$moment}</wsu:Created></wsu:Timestamp></wsse:Security></soapenv:Header>"
        ."<soapenv:Body xmlns:wsu=\"{$wsu}\" wsu:Id=\"id-body\">{$body}</soapenv:Body></soapenv:Envelope>";

    $document = new DOMDocument;
    $document->loadXML($envelope);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ds', $ds);
    $xpath->registerNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');

    $digest = base64_encode(hash('sha256', $xpath->query('/soapenv:Envelope/soapenv:Body')->item(0)->C14N(true, false), true));
    $xpath->query('//ds:DigestValue')->item(0)->textContent = $digest;

    openssl_sign($xpath->query('//ds:SignedInfo')->item(0)->C14N(true, false), $signature, $pki['key'], OPENSSL_ALGO_SHA256);
    $xpath->query('//ds:SignatureValue')->item(0)->textContent = base64_encode($signature);

    return (string) $document->saveXML($document->documentElement);
}

function customsResponseIsPdf(TestResponse $response): bool
{
    return str_starts_with((string) $response->getContent(), '%PDF');
}
