<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Enums\ExtractionMethod;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;

require_once __DIR__.'/OcrTestHelpers.php';

uses(RefreshDatabase::class);

const OCR = '/api/v1/ocr-inbox';

/**
 * The job queued for a DMS document (the queue is synchronous in tests, so the pipeline has already run).
 */
function ocrJobOf(string $documentId): DocumentExtractionJob
{
    return DocumentExtractionJob::withoutGlobalScopes()->where('dms_document_id', $documentId)->firstOrFail();
}

function ocrQueue(object $test, string $documentId): DocumentExtractionJob
{
    $test->postJson(OCR.'/documents', ['dms_document_id' => $documentId])->assertStatus(202);

    return ocrJobOf($documentId);
}

test('a FatturaPA XML bypasses the vision model completely and maps every field deterministically', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $document = ocrDmsDocument($company['tenant'], ocrFatturaPaXml(), 'fattura.xml', 'application/xml');

    $response = $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.extraction_method', 'embedded_xml_or_qr')
        ->assertJsonPath('data.ocr_provider_used', null)
        ->assertJsonPath('data.llm_tokens_used', 0)
        ->assertJsonPath('data.overall_confidence_score', 100)
        ->assertJsonPath('data.vendor_id', $company['vendor']);

    Http::assertNothingSent();

    $job = ocrJobOf($document->id);
    $payload = $job->extracted_payload_json;

    expect($job->id)->toBe($response->json('data.id'))
        ->and($payload['document']['number'])->toBe('FT-17')
        ->and($payload['totals']['gross'])->toBe(183)
        ->and($payload['lines'])->toHaveCount(2)
        ->and($payload['meta']['source_format'])->toBe('fatturapa')
        ->and($payload['field_confidence'])->each->toBe(100);
});

test('a ZUGFeRD PDF is read from its embedded XML with zero LLM tokens', function (): void {
    Http::fake();
    $company = ocrCompany('CH');
    ocrActAs($company['tenant']);
    $document = ocrDmsDocument($company['tenant'], ocrPdfWithAttachment(ocrCiiXml()), 'rechnung.pdf', 'application/pdf');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.extraction_method', 'embedded_xml_or_qr')
        ->assertJsonPath('data.llm_tokens_used', 0);

    Http::assertNothingSent();

    $payload = ocrJobOf($document->id)->extracted_payload_json;

    expect($payload['meta']['source_format'])->toBe('zugferd')
        ->and($payload['vendor']['uid'])->toBe('CHE-123.456.789')
        ->and($payload['totals']['gross'])->toBe(1190)
        ->and($payload['lines'][0]['description'])->toBe('Wartungsvertrag');
});

test('a Swiss QR-bill fixes the payment fields exactly and the OCR engine reads lines and the three Swiss VAT rates', function (): void {
    $company = ocrCompany('CH');
    ocrActAs($company['tenant']);
    ocrConfigure($company['tenant'], OcrProviderType::OllamaLocal, ['endpoint' => 'http://ollama.test:11434', 'model' => 'qwen2.5vl']);
    ocrFakeQrBill(ocrSwissBill());
    Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => json_encode(ocrSwissModelAnswer())], 'prompt_eval_count' => 900, 'eval_count' => 300])]);
    $document = ocrDmsDocument($company['tenant'], ocrPng(), 'qr-rechnung.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.extraction_method', 'hybrid_qr_plus_ocr')
        ->assertJsonPath('data.ocr_provider_used', 'ollama_local')
        ->assertJsonPath('data.llm_tokens_used', 1200)
        ->assertJsonPath('data.vendor_id', $company['vendor']);

    Http::assertSent(function (Request $request): bool {
        $prompt = (string) data_get($request->data(), 'messages.0.content');

        return $request->url() === 'http://ollama.test:11434/api/chat'
            && $request['model'] === 'qwen2.5vl'
            && $request['stream'] === false
            && isset($request['format']['properties']['vat_breakdown'])
            && count($request['messages'][0]['images']) === 1
            && str_contains($prompt, 'totals.gross') && str_contains($prompt, 'vendor.qr_iban');
    });

    $job = ocrJobOf($document->id);
    $payload = $job->extracted_payload_json;

    expect($payload['vendor']['qr_iban'])->toBe('CH4431999123000889012')
        ->and($payload['vendor']['name'])->toBe('Muster AG')
        ->and($payload['document']['qr_reference'])->toBe('210000000003139471430009017')
        ->and($payload['document']['qr_reference_type'])->toBe('QRR')
        ->and($payload['totals']['gross'])->toBe(1556.1)
        ->and($payload['field_confidence']['vendor.qr_iban'])->toBe(100)
        ->and($payload['field_confidence']['header.qr_reference'])->toBe(100)
        ->and($payload['field_confidence']['totals.gross'])->toBe(100)
        ->and($payload['field_confidence']['lines[0].unit_price'])->toBe(62)
        ->and(array_column($payload['vat_breakdown'], 'rate'))->toBe([8.1, 2.6, 3.8])
        ->and($payload['lines'])->toHaveCount(3)
        ->and($payload['meta']['qr_raw_payload'])->toStartWith('SPC')
        ->and($job->bounding_boxes_json)->toHaveKeys(['header.document_number', 'lines[0].unit_price'])
        ->and($job->bounding_boxes_json)->not->toHaveKey('bad')
        ->and($job->bounding_boxes_json['header.document_number'])->toBe(['page' => 1, 'x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.03]);
});

test('a real Swiss QR-bill image is decoded by the real parser: exact fields from the code, the rest from the engine', function (): void {
    $bill = QrBill::create();
    $bill->setCreditorInformation(CreditorInformation::create('CH4431999123000889012'));
    $bill->setCreditor(StructuredAddress::createWithStreet('Muster AG', 'Rue du Lac', '12', '2501', 'Biel', 'CH'));
    $bill->setPaymentAmountInformation(PaymentAmountInformation::create('CHF', 1556.10));
    $bill->setPaymentReference(PaymentReference::create(PaymentReference::TYPE_QR, '210000000003139471430009017'));
    $bill->setAdditionalInformation(AdditionalInformation::create('Invoice R-2026-17', '//S1/10/R-2026-17/11/260305/30/CHE-123.456.789'));

    $company = ocrCompany('CH');
    ocrActAs($company['tenant']);
    Http::fake(['localhost:11434/*' => Http::response(['message' => ['content' => json_encode(ocrSwissModelAnswer())], 'prompt_eval_count' => 5, 'eval_count' => 5])]);
    $document = ocrDmsDocument($company['tenant'], $bill->getQrCode()->getAsString('png'), 'qr-code.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.extraction_method', 'hybrid_qr_plus_ocr')
        ->assertJsonPath('data.vendor_id', $company['vendor']);

    $payload = ocrJobOf($document->id)->extracted_payload_json;

    expect($payload['vendor']['qr_iban'])->toBe('CH4431999123000889012')
        ->and($payload['vendor']['name'])->toBe('Muster AG')
        ->and($payload['document']['qr_reference'])->toBe('210000000003139471430009017')
        ->and($payload['document']['qr_reference_type'])->toBe('QRR')
        ->and($payload['document']['number'])->toBe('R-2026-17')
        ->and($payload['document']['issue_date'])->toBe('2026-03-05')
        ->and($payload['totals']['gross'])->toBe(1556.1)
        ->and($payload['field_confidence']['totals.gross'])->toBe(100)
        ->and($payload['field_confidence']['header.qr_reference'])->toBe(100)
        ->and($payload['meta']['qr_raw_payload'])->toStartWith('SPC')
        ->and(array_column($payload['vat_breakdown'], 'rate'))->toBe([8.1, 2.6, 3.8])
        ->and($payload['lines'])->toHaveCount(3);
});

test('a QR-bill the OCR engine cannot read is still kept with its exact fields and a warning', function (): void {
    $company = ocrCompany('CH');
    ocrActAs($company['tenant']);
    ocrFakeQrBill(ocrSwissBill());
    Http::fake(fn () => throw new ConnectionException('connection refused'));
    $document = ocrDmsDocument($company['tenant'], ocrPng(), 'qr.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.extraction_method', 'hybrid_qr_plus_ocr')
        ->assertJsonPath('data.ocr_provider_used', null);

    $payload = ocrJobOf($document->id)->extracted_payload_json;

    expect($payload['meta']['warnings'])->toBe(['ocr_unavailable'])
        ->and($payload['lines'])->toBe([])
        ->and($payload['totals']['gross'])->toBe(1556.1);
});

test('a document with no structure goes to the tenant engine in full, and a total failure is recorded', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    ocrFakeQrBill(null);
    Http::fake(['localhost:11434/*' => Http::sequence()
        ->push(['message' => ['content' => json_encode(ocrSwissModelAnswer())], 'prompt_eval_count' => 10, 'eval_count' => 5])
        ->push('boom', 500)]);
    $document = ocrDmsDocument($company['tenant'], ocrPng(), 'scontrino.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id, 'document_category' => 'expense_receipt'])
        ->assertStatus(202)
        ->assertJsonPath('data.extraction_method', 'vision_llm_ocr')
        ->assertJsonPath('data.document_category', 'expense_receipt')
        ->assertJsonPath('data.llm_tokens_used', 15);

    $broken = ocrDmsDocument($company['tenant'], ocrPng(), 'altro.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $broken->id])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'failed');

    expect(ocrJobOf($broken->id)->failure_reason)->toContain('ollama_local')->toContain('HTTP 500');
});

test('a tenant switches between the local engine and a cloud engine, each with its own settings and consent', function (): void {
    $cloud = ocrCompany('CH');
    $local = ocrCompany('CH');
    ocrFakeQrBill(null);

    // The cloud tenant chose Gemini but has not consented to external providers: nothing leaves, the job fails.
    ocrConfigure($cloud['tenant'], OcrProviderType::GeminiCloud, ['api_key' => 'KEY-OF-CLOUD-TENANT']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(ocrSwissModelAnswer())]]]]], 'usageMetadata' => ['totalTokenCount' => 777]]),
        'localhost:11434/*' => Http::response(['message' => ['content' => json_encode(ocrSwissModelAnswer())]]),
    ]);
    ocrActAs($cloud['tenant']);
    $blocked = ocrDmsDocument($cloud['tenant'], ocrPng(), 'a.png', 'image/png');
    $this->postJson(OCR.'/documents', ['dms_document_id' => $blocked->id])->assertJsonPath('data.status', 'failed');
    Http::assertNothingSent();
    expect(ocrJobOf($blocked->id)->failure_reason)->toContain('has not allowed external AI providers');

    // With consent the same configuration works through the Gemini adapter and its own key.
    ocrConsent($cloud['tenant']);
    $allowed = ocrDmsDocument($cloud['tenant'], ocrPng(), 'b.png', 'image/png');
    $this->postJson(OCR.'/documents', ['dms_document_id' => $allowed->id])
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.ocr_provider_used', 'gemini_cloud')
        ->assertJsonPath('data.llm_tokens_used', 777);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com') && $request->header('x-goog-api-key') === ['KEY-OF-CLOUD-TENANT'] && ! str_contains($request->url(), 'KEY-OF'));

    // Another tenant on the default local engine never reaches Google and never sees that key.
    ocrActAs($local['tenant']);
    $own = ocrDmsDocument($local['tenant'], ocrPng(), 'c.png', 'image/png');
    $this->postJson(OCR.'/documents', ['dms_document_id' => $own->id])->assertJsonPath('data.ocr_provider_used', 'ollama_local');
    $recorded = Http::recorded();
    expect($recorded->last()[0]->url())->toStartWith('http://localhost:11434')
        ->and($recorded->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), 'generativelanguage'))->count())->toBe(1)
        ->and($recorded->last()[0]->hasHeader('x-goog-api-key'))->toBeFalse();
});

test('the fallback engine takes over when the primary one is unreachable', function (): void {
    $company = ocrCompany('CH');
    ocrFakeQrBill(null);
    ocrConfigure($company['tenant'], OcrProviderType::OllamaLocal, ['endpoint' => 'http://ollama.test:11434'], OcrProviderType::OpenAiCloud, true);
    $configuration = DB::table('tenant_ocr_configurations')->where('tenant_id', $company['tenant']->id);
    expect($configuration->exists())->toBeTrue();

    // The fallback needs its own key: store it with the model that encrypts it.
    app(TenantContext::class)->runWithTenant($company['tenant'], function () use ($company): void {
        $config = TenantOcrConfiguration::query()->where('tenant_id', $company['tenant']->id)->firstOrFail();
        $config->update(['provider_settings_encrypted' => ['ollama_local' => ['endpoint' => 'http://ollama.test:11434'], 'openai_cloud' => ['api_key' => 'sk-test']]]);
    });

    Http::fake([
        'ollama.test:11434/*' => fn () => throw new ConnectionException('connection refused'),
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(ocrSwissModelAnswer())]]], 'usage' => ['total_tokens' => 321]]),
    ]);
    ocrActAs($company['tenant']);
    $document = ocrDmsDocument($company['tenant'], ocrPng(), 'd.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.ocr_provider_used', 'openai_cloud')
        ->assertJsonPath('data.llm_tokens_used', 321);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com') && $request->hasHeader('Authorization', 'Bearer sk-test'));
});

test('Azure Document Intelligence is polled until it finishes and its polygons become page ratios', function (): void {
    $company = ocrCompany('CH');
    ocrFakeQrBill(null);
    config(['ocr_extraction.azure.poll_interval_ms' => 0]);
    ocrConfigure($company['tenant'], OcrProviderType::AzureDocumentIntelligence, ['endpoint' => 'https://acme.cognitiveservices.azure.com', 'api_key' => 'azure-key'], null, true);

    $analysis = ['status' => 'succeeded', 'analyzeResult' => [
        'pages' => [['pageNumber' => 1, 'width' => 8.5, 'height' => 11]],
        'documents' => [['fields' => [
            'VendorName' => ['valueString' => 'Muster AG', 'confidence' => 0.97, 'boundingRegions' => [['pageNumber' => 1, 'polygon' => [0.85, 1.1, 4.25, 1.1, 4.25, 1.43, 0.85, 1.43]]]],
            'VendorTaxId' => ['valueString' => 'CHE-123.456.789', 'confidence' => 0.9],
            'InvoiceId' => ['valueString' => 'R-9', 'confidence' => 0.99],
            'InvoiceTotal' => ['valueCurrency' => ['amount' => 108.1, 'currencyCode' => 'CHF'], 'confidence' => 0.95],
            'Items' => ['valueArray' => [['valueObject' => ['Description' => ['valueString' => 'Montage', 'confidence' => 0.9], 'Quantity' => ['valueNumber' => 1, 'confidence' => 0.9], 'UnitPrice' => ['valueCurrency' => ['amount' => 100]], 'Amount' => ['valueCurrency' => ['amount' => 100]]]]]],
        ]]],
    ]];

    Http::fake([
        'acme.cognitiveservices.azure.com/documentintelligence/documentModels/prebuilt-invoice:analyze*' => Http::response('', 202, ['Operation-Location' => 'https://acme.cognitiveservices.azure.com/operations/42']),
        'acme.cognitiveservices.azure.com/operations/42' => Http::sequence()->push(['status' => 'running'])->push($analysis),
    ]);
    ocrActAs($company['tenant']);
    $document = ocrDmsDocument($company['tenant'], ocrPng(), 'e.png', 'image/png');

    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])
        ->assertJsonPath('data.status', 'ready_for_review')
        ->assertJsonPath('data.ocr_provider_used', 'azure_document_intelligence');

    $job = ocrJobOf($document->id);

    expect($job->extracted_payload_json['vendor']['uid'])->toBe('CHE-123.456.789')
        ->and($job->extracted_payload_json['totals']['gross'])->toBe(108.1)
        ->and($job->extracted_payload_json['field_confidence']['vendor.name'])->toBe(97)
        ->and($job->bounding_boxes_json['vendor.name'])->toBe(['page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.03]);
});

test('an open purchase order and its goods receipt are surfaced with the price and quantity variances', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    $user = ocrActAs($company['tenant']);
    $tenantId = (string) $company['tenant']->id;
    [$order, $line1, $line2, $receipt] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $now = now();

    DB::table('purchase_orders')->insert(['id' => $order, 'tenant_id' => $tenantId, 'supplier_id' => $company['vendor'], 'order_number' => 'PO-2026-77', 'po_number' => 'PO-2026-77', 'date' => '2026-02-20', 'status' => 'sent', 'currency' => 'EUR', 'total_net' => 140, 'total_tax' => 30.8, 'total_gross' => 170.8, 'total_amount' => 170.8, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('purchase_order_lines')->insert([
        ['id' => $line1, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 1, 'description' => 'Toner nero HP', 'quantity_ordered' => 2, 'quantity_received' => 2, 'unit_price' => 45, 'tax_rate' => 22, 'line_net_amount' => 90, 'line_tax_amount' => 19.8, 'line_total_amount' => 109.8, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $line2, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 2, 'description' => 'Carta A4', 'quantity_ordered' => 10, 'quantity_received' => 4, 'unit_price' => 5, 'tax_rate' => 22, 'line_net_amount' => 50, 'line_tax_amount' => 11, 'line_total_amount' => 61, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('purchase_receipts')->insert(['id' => $receipt, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'receipt_number' => 'GR-1', 'supplier_delivery_note_ref' => 'DDT-9', 'received_date' => '2026-03-01', 'received_by_user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now]);

    $document = ocrDmsDocument($company['tenant'], ocrFatturaPaXml(), 'fattura.xml', 'application/xml');
    $this->postJson(OCR.'/documents', ['dms_document_id' => $document->id])->assertStatus(202);

    $job = ocrJobOf($document->id);
    $match = $job->extracted_payload_json['three_way_match'][0];

    expect($job->matched_purchase_order_ids_json)->toBe([$order])
        ->and($job->matched_goods_receipt_ids_json)->toBe([$receipt])
        ->and($match['purchase_order_number'])->toBe('PO-2026-77')
        ->and($match['matched_by'])->toBe('reference')
        ->and($match['status'])->toBe('variance')
        ->and($match['lines'][0]['purchase_order_line_id'])->toBe($line1)
        ->and($match['lines'][0]['price_variance'])->toBe(5)
        ->and($match['lines'][0]['price_variance_percent'])->toBe(11.11)
        ->and($match['lines'][0]['within_tolerance'])->toBeFalse()
        ->and($match['lines'][1]['received_variance'])->toBe(6)
        ->and($match['lines'][1]['quantity_variance'])->toBe(0);

    $workbench = $this->getJson(OCR.'/'.$job->id)->assertOk()->json('data');

    expect($workbench['three_way_match']['purchase_orders'][0]['goods_receipts'][0]['receipt_number'])->toBe('GR-1')
        ->and($workbench['three_way_match']['purchase_orders'][0]['lines'])->toHaveCount(2)
        ->and($workbench['three_way_match']['purchase_orders'][0]['evaluation']['status'])->toBe('variance');
});

test('the queue lists the inbox with counters and the workbench carries a signed source URL and the coding lookups', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $document = ocrDmsDocument($company['tenant'], ocrFatturaPaXml(), 'fattura.xml', 'application/xml');
    $job = ocrQueue($this, $document->id);

    $index = $this->getJson(OCR)->assertOk()->json('data');

    expect($index['counters'])->toBe(['queued' => 0, 'processing' => 0, 'ready_for_review' => 1, 'failed' => 0])
        ->and($index['queue'])->toHaveCount(1)
        ->and($index['queue'][0]['vendor_name'])->toBe('Muster AG')
        ->and($index['queue'][0]['document_title'])->toBe('fattura.xml')
        ->and($index['selected'])->toBeNull();

    $this->getJson(OCR.'?status=failed')->assertOk()->assertJsonPath('data.meta.total', 0);

    $workbench = $this->getJson(OCR.'/'.$job->id)->assertOk()->json('data');

    expect($workbench['source']['mime_type'])->toBe('application/xml')
        ->and($workbench['vendor']['company_name'])->toBe('Muster AG')
        ->and(array_column($workbench['lookups']['gl_accounts'], 'code'))->toContain('6900')
        ->and(array_column($workbench['lookups']['vat_codes'], 'code'))->toHaveCount(2)->each->toStartWith('IT-')
        ->and($workbench['lookups']['cost_centers'][0]['name'])->toBe('Werkstatt');

    // The signed URL serves the file without a bearer token and cannot be tampered with.
    $this->withoutHeader('X-Tenant-Id')->get($workbench['source']['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $workbench['source']['url']))->assertForbidden();
    $this->get(url('/api/v1/ocr-inbox-downloads/'.$job->id))->assertForbidden();
});

test('a tenant never sees or touches the jobs of another tenant', function (): void {
    Http::fake();
    $first = ocrCompany('IT');
    $second = ocrCompany('IT');
    ocrActAs($first['tenant']);
    $job = ocrQueue($this, ocrDmsDocument($first['tenant'], ocrFatturaPaXml(), 'fattura.xml', 'application/xml')->id);

    ocrActAs($second['tenant']);

    $this->getJson(OCR)->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->getJson(OCR.'/'.$job->id)->assertNotFound();
    $this->postJson(OCR.'/'.$job->id.'/reject', ['reason' => 'x'])->assertNotFound();
    $this->postJson(OCR.'/documents', ['dms_document_id' => $job->dms_document_id])->assertUnprocessable();
    expect(ocrJobOf($job->dms_document_id)->status)->toBe(ExtractionJobStatus::ReadyForReview);
});

test('the pipeline enum states are exposed in the module', function (): void {
    expect(ExtractionMethod::EmbeddedXmlOrQr->usesOcrProvider())->toBeFalse()
        ->and(ExtractionMethod::HybridQrPlusOcr->usesOcrProvider())->toBeTrue()
        ->and(OcrProviderType::OllamaLocal->isCloud())->toBeFalse()
        ->and(OcrProviderType::AzureDocumentIntelligence->isCloud())->toBeTrue()
        ->and(ExtractionJobStatus::inbox())->toHaveCount(4);
});
