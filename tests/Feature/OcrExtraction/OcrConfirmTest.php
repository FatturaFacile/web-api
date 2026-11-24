<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\VendorCodingPredictionModel;

require_once __DIR__.'/OcrTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake();
});

test('confirming a vendor invoice posts it, links the file, audits it and teaches the vendor coding', function (): void {
    $company = ocrCompany('IT');
    $user = ocrActAs($company['tenant']);
    $job = ocrReadyJob($this, $company, 'FT-17');

    ocrConfirm($this, $job, ocrReviewed($job, $company, [0 => ['cost_center_id' => $company['cost_center']]]))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.resulting_entity_type', 'supplier_invoice')
        ->assertJsonPath('data.confirmed_by_user_id', $user->id);

    $job->refresh();
    $invoice = PassiveInvoice::withoutGlobalScopes()->findOrFail($job->resulting_entity_id);

    expect($job->status)->toBe(ExtractionJobStatus::Confirmed)
        ->and($invoice->invoice_number)->toBe('FT-17')
        ->and($invoice->supplier_id)->toBe($company['vendor'])
        ->and((float) $invoice->total_amount)->toBe(183.0)
        ->and((float) $invoice->total_tax)->toBe(33.0)
        ->and($invoice->cost_account_id)->toBe($company['account'])
        ->and(DB::table('passive_invoice_lines')->where('passive_invoice_id', $invoice->id)->count())->toBe(2)
        ->and(DB::table('journal_entries')->where('source_document_type', 'vendor_invoice')->where('source_document_id', $invoice->id)->count())->toBe(1)
        ->and(DB::table('dms_document_links')->where('document_id', $job->dms_document_id)->where('linkable_type', 'supplier_invoice')->where('linkable_id', $invoice->id)->exists())->toBeTrue()
        ->and(json_decode((string) DB::table('dms_documents')->where('id', $job->dms_document_id)->value('metadata'), true)['ocr_extraction']['preservation_candidate'])->toBeTrue()
        ->and(VendorCodingPredictionModel::withoutGlobalScopes()->where('vendor_id', $company['vendor'])->count())->toBe(2);

    $audit = DB::table('enterprise_audit_ledger')->where('action', 'OCR_EXTRACTION_CONFIRMED')->where('resource_id', $job->id)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($user->id)
        ->and(json_decode((string) $audit->payload_after, true)['corrected_fields'])->toContain('lines[0].gl_account_id', 'lines[0].cost_center_id');

    expect(DB::table('enterprise_audit_ledger')->where('action', 'OCR_EXTRACTION_COMPLETED')->where('resource_id', $job->id)->exists())->toBeTrue();
});

test('a corrected GL account is learned and the next invoice of the vendor predicts it', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);

    // First invoice: nothing is known, the accountant codes both lines with account A.
    $first = ocrReadyJob($this, $company, 'FT-1');
    expect($first->extracted_payload_json['lines'][0]['coding_source'])->toBe('none')
        ->and($first->extracted_payload_json['lines'][0]['suggested_gl_account_id'])->toBeNull();
    ocrConfirm($this, $first, ocrReviewed($first, $company))->assertOk();

    // Second invoice: both lines are predicted exactly, with full confidence, and the accountant accepts them.
    $second = ocrReadyJob($this, $company, 'FT-2');
    $lines = $second->extracted_payload_json['lines'];

    expect($lines[0]['coding_source'])->toBe('exact')
        ->and($lines[0]['suggested_gl_account_id'])->toBe($company['account'])
        ->and($lines[0]['suggested_vat_code_id'])->toBe($company['vat'])
        ->and($lines[0]['coding_confidence'])->toBe(100);

    // The accountant changes the account of the first line to B and keeps the second line as suggested.
    ocrConfirm($this, $second, ocrReviewed($second, $company, [0 => ['gl_account_id' => $company['account_b'], 'vat_code_id' => $company['vat_reduced']]]))->assertOk();

    $toner = VendorCodingPredictionModel::withoutGlobalScopes()->where('vendor_id', $company['vendor'])->where('line_description_pattern', 'hp nero toner')->firstOrFail();
    $paper = VendorCodingPredictionModel::withoutGlobalScopes()->where('vendor_id', $company['vendor'])->where('line_description_pattern', 'carta')->firstOrFail();

    expect($toner->suggested_gl_account_id)->toBe($company['account_b'])
        ->and($toner->suggested_vat_code_id)->toBe($company['vat_reduced'])
        ->and($toner->historical_hit_count)->toBe(1)
        ->and($toner->correction_count)->toBe(1)
        ->and((float) $toner->accuracy_rate)->toBe(50.0)
        ->and($paper->historical_hit_count)->toBe(2)
        ->and($paper->correction_count)->toBe(0)
        ->and((float) $paper->accuracy_rate)->toBe(100.0);

    // Third invoice: the corrected coding is predicted at once, at the confidence the correction history earns.
    $third = ocrReadyJob($this, $company, 'FT-3');
    $predicted = $third->extracted_payload_json['lines'];

    expect($predicted[0]['suggested_gl_account_id'])->toBe($company['account_b'])
        ->and($predicted[0]['suggested_vat_code_id'])->toBe($company['vat_reduced'])
        ->and($predicted[0]['coding_confidence'])->toBe(50)
        ->and($predicted[0]['prediction_model_id'])->toBe($toner->id)
        ->and($predicted[1]['suggested_gl_account_id'])->toBe($company['account'])
        ->and($predicted[1]['coding_confidence'])->toBe(100);
});

test('a line unseen before falls back to the usual coding of the vendor', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $first = ocrReadyJob($this, $company, 'FT-1');
    ocrConfirm($this, $first, ocrReviewed($first, $company))->assertOk();

    $xml = str_replace(['FT-17', 'Toner nero HP', 'Carta A4'], ['FT-9', 'Servizio di facchinaggio straordinario', 'Noleggio gru'], ocrFatturaPaXml());
    $document = ocrDmsDocument($company['tenant'], $xml, 'ft9.xml', 'application/xml');
    $this->postJson('/api/v1/ocr-inbox/documents', ['dms_document_id' => $document->id])->assertStatus(202);
    $line = DocumentExtractionJob::withoutGlobalScopes()->where('dms_document_id', $document->id)->firstOrFail()->extracted_payload_json['lines'][0];

    expect($line['coding_source'])->toBe('vendor_default')
        ->and($line['suggested_gl_account_id'])->toBe($company['account'])
        ->and($line['coding_confidence'])->toBeLessThanOrEqual(50);
});

test('the business rules stop a document that cannot be posted, and nothing is left behind', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $job = ocrReadyJob($this, $company, 'FT-17');
    $reviewed = ocrReviewed($job, $company);
    $invoices = fn (): int => PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $company['tenant']->id)->count();

    // Totals that do not reconcile.
    ocrConfirm($this, $job, ocrReviewed($job, $company, [], ['totals' => ['net' => 150, 'vat' => 33, 'gross' => 190]]))
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'totals do not reconcile'));

    // A line without accounting coding.
    ocrConfirm($this, $job, ocrReviewed($job, $company, [1 => ['gl_account_id' => null]]))->assertUnprocessable();

    // A VAT code of the other jurisdiction (the company is Italian).
    $swiss = (string) Str::uuid();
    DB::table('vat_codes')->insert(['id' => $swiss, 'tenant_id' => $company['tenant']->id, 'code' => 'CH-X-'.Str::random(4), 'country_iso' => 'CH', 'rate' => 8.1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    ocrConfirm($this, $job, ocrReviewed($job, $company, [0 => ['vat_code_id' => $swiss]]))
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'jurisdiction'));

    // An account of another tenant.
    $other = ocrCompany('IT');
    ocrConfirm($this, $job, ocrReviewed($job, $company, [0 => ['gl_account_id' => $other['account']]]))->assertUnprocessable();

    // A closed fiscal period.
    DB::table('fiscal_periods')->where('tenant_id', $company['tenant']->id)->where('start_date', '<=', '2026-03-05')->where('end_date', '>=', '2026-03-05')->update(['status' => 'closed']);
    ocrConfirm($this, $job, $reviewed)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'closed'));
    DB::table('fiscal_periods')->where('tenant_id', $company['tenant']->id)->update(['status' => 'open']);

    expect($invoices())->toBe(0)
        ->and($job->refresh()->status)->toBe(ExtractionJobStatus::ReadyForReview)
        ->and(VendorCodingPredictionModel::withoutGlobalScopes()->count())->toBe(0);

    ocrConfirm($this, $job, $reviewed)->assertOk();
    expect($invoices())->toBe(1);

    // Confirming again, or registering the same invoice from another file, is refused.
    ocrConfirm($this, $job, $reviewed)->assertUnprocessable();
    $again = ocrReadyJob($this, $company, 'FT-17');
    ocrConfirm($this, $again, ocrReviewed($again, $company))->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already registered'));
    expect($invoices())->toBe(1);
});

test('an invoice outside the tolerance of its purchase order is blocked unless the accountant gives a reason', function (): void {
    $company = ocrCompany('IT');
    $user = ocrActAs($company['tenant']);
    $tenantId = (string) $company['tenant']->id;
    [$order, $line1, $line2] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $now = now();
    DB::table('purchase_orders')->insert(['id' => $order, 'tenant_id' => $tenantId, 'supplier_id' => $company['vendor'], 'order_number' => 'PO-2026-77', 'po_number' => 'PO-2026-77', 'date' => '2026-02-20', 'status' => 'sent', 'currency' => 'EUR', 'total_net' => 140, 'total_tax' => 30.8, 'total_gross' => 170.8, 'total_amount' => 170.8, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('purchase_order_lines')->insert([
        ['id' => $line1, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 1, 'description' => 'Toner nero HP', 'quantity_ordered' => 2, 'quantity_received' => 2, 'unit_price' => 45, 'tax_rate' => 22, 'line_net_amount' => 90, 'line_tax_amount' => 19.8, 'line_total_amount' => 109.8, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $line2, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 2, 'description' => 'Carta A4', 'quantity_ordered' => 10, 'quantity_received' => 10, 'unit_price' => 5, 'tax_rate' => 22, 'line_net_amount' => 50, 'line_tax_amount' => 11, 'line_total_amount' => 61, 'created_at' => $now, 'updated_at' => $now],
    ]);
    $job = ocrReadyJob($this, $company, 'FT-17');
    $reviewed = ocrReviewed($job, $company, [], ['purchase_order_ids' => [$order]]);

    ocrConfirm($this, $job, $reviewed)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'PO-2026-77') && str_contains($detail, 'tolerance'));
    expect($job->refresh()->status)->toBe(ExtractionJobStatus::ReadyForReview);

    ocrConfirm($this, $job, [...$reviewed, 'match_override_reason' => 'Supplier raised the toner price, agreed by phone'])->assertOk();

    $job->refresh();
    $audit = DB::table('enterprise_audit_ledger')->where('action', 'OCR_EXTRACTION_CONFIRMED')->where('resource_id', $job->id)->first();

    expect($job->matched_purchase_order_ids_json)->toBe([$order])
        ->and(PassiveInvoice::withoutGlobalScopes()->find($job->resulting_entity_id)->purchase_order_id)->toBe($order)
        ->and(json_decode((string) $audit->payload_after, true)['match_override_reason'])->toBe('Supplier raised the toner price, agreed by phone')
        ->and($audit->actor_user_id)->toBe($user->id);
});

test('a draft is saved, a document is rejected, and an extraction is queued again', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $job = ocrReadyJob($this, $company, 'FT-17');
    $reviewed = ocrReviewed($job, $company);

    $this->putJson('/api/v1/ocr-inbox/'.$job->id.'/draft', $reviewed)->assertOk();
    expect($job->refresh()->review_draft_json['header']['number'])->toBe('FT-17');
    $this->getJson('/api/v1/ocr-inbox/'.$job->id)->assertOk()->assertJsonPath('data.review_draft.header.number', 'FT-17');

    $this->postJson('/api/v1/ocr-inbox/'.$job->id.'/rerun')->assertStatus(202)->assertJsonPath('data.status', 'ready_for_review');
    expect($job->refresh()->review_draft_json)->toBeNull();

    $this->postJson('/api/v1/ocr-inbox/'.$job->id.'/reject', [])->assertUnprocessable();
    $this->postJson('/api/v1/ocr-inbox/'.$job->id.'/reject', ['reason' => 'Not addressed to us'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson('/api/v1/ocr-inbox/'.$job->id.'/rerun')->assertUnprocessable();
    ocrConfirm($this, $job, $reviewed)->assertUnprocessable();

    expect(DB::table('enterprise_audit_ledger')->where('resource_id', $job->id)->pluck('action')->all())
        ->toContain('OCR_EXTRACTION_ENQUEUED', 'OCR_EXTRACTION_DRAFT_SAVED', 'OCR_EXTRACTION_RERUN', 'OCR_EXTRACTION_REJECTED')
        ->and(PassiveInvoice::withoutGlobalScopes()->count())->toBe(0);
});

test('roles outside finance cannot review, but any member may capture', function (): void {
    $company = ocrCompany('IT');
    $job = (function () use ($company): DocumentExtractionJob {
        ocrActAs($company['tenant']);

        return ocrReadyJob(test(), $company, 'FT-17');
    })();

    ocrActAs($company['tenant'], 'member');
    $this->getJson('/api/v1/ocr-inbox')->assertForbidden();
    $this->postJson('/api/v1/ocr-inbox/'.$job->id.'/confirm', ocrReviewed($job, $company))->assertForbidden();
    $this->getJson('/api/v1/ocr-inbox/configuration')->assertForbidden();

    ocrActAs($company['tenant'], 'accountant');
    $this->getJson('/api/v1/ocr-inbox/configuration')->assertForbidden();
    $this->getJson('/api/v1/ocr-inbox')->assertOk();
});
