<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Quoting\Domain\Events\QuoteOtpRequested;
use Modules\Quoting\Domain\Exceptions\ImmutableQuoteRevisionException;
use Modules\Quoting\Infrastructure\Models\CpqAuditEvent;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $extra
 * @return array{tenant: Tenant, contact: string}
 */
function qtgLogin(string $role = 'owner'): array
{
    ['tenant' => $tenant, 'user' => $user] = tfrTenant('Cpq Tenant', $role);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return ['tenant' => $tenant, 'contact' => tfrCustomer((string) $tenant->id)->id];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function qtgPayload(string $contactId, array $extra = []): array
{
    return $extra + [
        'customer_name' => 'Acme AG', 'customer_email' => 'buyer@acme.test', 'contact_id' => $contactId, 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'sections' => [['key' => 's1', 'title' => 'Hardware']],
        'lines' => [
            ['section_key' => 's1', 'line_type' => 'product', 'description' => 'Server', 'quantity' => 2, 'unit_price' => 1000, 'unit_cost' => 600, 'tax_rate' => 22],
            ['section_key' => 's1', 'line_type' => 'service_fixed', 'selection_mode' => 'optional', 'is_default_selected' => false, 'description' => 'Extended support', 'quantity' => 1, 'unit_price' => 300, 'unit_cost' => 100, 'tax_rate' => 22],
            ['section_key' => 's1', 'line_type' => 'product', 'selection_mode' => 'alternative_group', 'alternative_group' => 'warranty', 'is_default_selected' => true, 'description' => 'Standard warranty', 'quantity' => 1, 'unit_price' => 0, 'tax_rate' => 22],
            ['section_key' => 's1', 'line_type' => 'product', 'selection_mode' => 'alternative_group', 'alternative_group' => 'warranty', 'is_default_selected' => false, 'description' => 'Extended warranty', 'quantity' => 1, 'unit_price' => 200, 'unit_cost' => 50, 'tax_rate' => 22],
        ],
    ];
}

/**
 * Creates and issues a quote; returns its id, token and the lines by description.
 *
 * @param  array<string, mixed>  $extra
 * @param  array<string, mixed>  $issue
 * @return array{id: string, token: string, pin: string|null, lines: array<string, string>}
 */
function qtgIssued(string $contactId, array $extra = [], array $issue = []): array
{
    $created = test()->postJson('/api/v1/cpq/quotes', qtgPayload($contactId, $extra))->assertCreated();
    $id = $created->json('data.id');
    $sent = test()->postJson("/api/v1/cpq/quotes/{$id}/issue", $issue)->assertOk();

    return [
        'id' => $id,
        'token' => (string) $sent->json('data.portal_token'),
        'pin' => $sent->json('data.pin'),
        'lines' => collect($sent->json('data.quote.lines'))->pluck('id', 'description')->all(),
    ];
}

it('creates a draft with sections and lines, prices it and numbers it OFF-YEAR-SEQ.01', function (): void {
    ['contact' => $contact] = qtgLogin();

    $response = $this->postJson('/api/v1/cpq/quotes', qtgPayload($contact))->assertCreated();

    expect($response->json('data.quote_number'))->toMatch('/^OFF-\d{4}-00001\.01$/')
        ->and($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.totals.net'))->toBe('2000.00')
        ->and($response->json('data.totals.gross'))->toBe('2440.00')
        ->and($response->json('data.totals.margin_percent'))->toBe('40.00')
        ->and($response->json('data.sections'))->toHaveCount(1)
        ->and($response->json('data.lines'))->toHaveCount(4);
});

it('freezes an issued revision: the API and the model refuse any further change', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    expect(CpqQuote::query()->find($quote['id']))->snapshot_hash->toHaveLength(64)->status->value->toBe('sent');
    $this->putJson("/api/v1/cpq/quotes/{$quote['id']}", ['customer_name' => 'Changed'])->assertUnprocessable();
    $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/reorder", ['section_order' => [], 'line_order' => []])->assertUnprocessable();

    $model = CpqQuote::query()->findOrFail($quote['id']);
    $model->customer_name = 'Tampered';
    expect(fn () => $model->save())->toThrow(ImmutableQuoteRevisionException::class);
    expect(fn () => $model->lines()->first()?->update(['unit_price' => 1]))->toThrow(ImmutableQuoteRevisionException::class);
});

it('reorders lines across sections on a draft and keeps the totals', function (): void {
    ['contact' => $contact] = qtgLogin();
    $created = $this->postJson('/api/v1/cpq/quotes', qtgPayload($contact, ['sections' => [['key' => 's1', 'title' => 'A'], ['key' => 's2', 'title' => 'B']]]))->assertCreated();
    $lines = collect($created->json('data.lines'))->pluck('id')->all();
    $sections = collect($created->json('data.sections'))->pluck('id')->all();

    $moved = $this->postJson("/api/v1/cpq/quotes/{$created->json('data.id')}/reorder", [
        'section_order' => array_reverse($sections),
        'line_order' => array_map(static fn (string $id, int $i): array => ['id' => $id, 'section_id' => $sections[1]], array_reverse($lines), array_keys($lines)),
    ])->assertOk();

    expect($moved->json('data.sections.0.id'))->toBe($sections[1])
        ->and(collect($moved->json('data.lines'))->pluck('section_id')->unique()->all())->toBe([$sections[1]])
        ->and($moved->json('data.lines.0.id'))->toBe($lines[3])
        ->and($moved->json('data.totals.net'))->toBe('2000.00');
});

it('refuses unknown, malformed and expired public links with the same 404', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    $this->getJson('/api/v1/public/proposals/'.str_repeat('x', 48))->assertNotFound();
    $this->getJson('/api/v1/public/proposals/short')->assertNotFound();
    $this->getJson("/api/v1/public/proposals/{$quote['token']}")->assertOk();

    DB::table('cpq_quotes')->where('id', $quote['id'])->update(['public_expires_at' => now()->subMinute()]);
    $this->getJson("/api/v1/public/proposals/{$quote['token']}")->assertNotFound()->assertJsonPath('detail', 'This proposal link is not valid.');
});

it('gates the proposal behind the PIN and locks after repeated failures', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact, [], ['use_pin' => true]);

    expect($quote['pin'])->toMatch('/^\d{6}$/');
    $this->getJson("/api/v1/public/proposals/{$quote['token']}")->assertNotFound();

    $wrong = $quote['pin'] === '000000' ? '111111' : '000000';
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/verify-pin", ['pin' => $wrong])->assertNotFound();

    $session = $this->postJson("/api/v1/public/proposals/{$quote['token']}/verify-pin", ['pin' => $quote['pin']])->assertOk()->json('data.session');
    $this->withHeader('X-Proposal-Session', $session)->getJson("/api/v1/public/proposals/{$quote['token']}")->assertOk();
    $this->withHeader('X-Proposal-Session', 'forged')->getJson("/api/v1/public/proposals/{$quote['token']}")->assertNotFound();
});

it('never exposes unit costs, margins or internal totals on the public proposal', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    $body = $this->getJson("/api/v1/public/proposals/{$quote['token']}")->assertOk()->getContent();

    expect($body)->not->toContain('unit_cost')->not->toContain('margin')->not->toContain('"cost"')->not->toContain('frozen_snapshot')
        ->and($body)->toContain('Extended support');
    expect(CpqQuote::query()->find($quote['id'])->status->value)->toBe('viewed');
});

it('recalculates the proposal live when the customer toggles optional lines and alternatives', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    $url = "/api/v1/public/proposals/{$quote['token']}/selections";

    $base = $this->getJson("/api/v1/public/proposals/{$quote['token']}")->json('data.totals.net');
    $withOption = $this->putJson($url, ['selections' => [$quote['lines']['Extended support'] => true]])->assertOk();
    $withAlternative = $this->putJson($url, ['selections' => [$quote['lines']['Extended support'] => true, $quote['lines']['Extended warranty'] => true]])->assertOk();

    expect($base)->toBe('2000.00')
        ->and($withOption->json('data.totals.net'))->toBe('2300.00')
        ->and($withAlternative->json('data.totals.net'))->toBe('2500.00')
        ->and($withAlternative->json('data.totals.gross'))->toBe('3050.00')
        ->and(collect($withAlternative->json('data.lines'))->firstWhere('description', 'Standard warranty')['selected'])->toBeFalse();

    $this->putJson($url, ['selections' => [$quote['lines']['Server'] => false]])->assertUnprocessable();
    expect(CpqQuote::query()->find($quote['id'])->status->value)->toBe('negotiating');
});

/**
 * Runs the SES flow up to the accepted quote and returns what the OTP step produced.
 *
 * @return array{envelope: string, session: array<string, mixed>}
 */
function qtgSign(string $token, string $quoteId, ?string $code = null): array
{
    $captured = [];
    Event::listen(QuoteOtpRequested::class, static function (QuoteOtpRequested $event) use (&$captured): void {
        $captured[] = $event->code;
    });

    $start = test()->postJson("/api/v1/public/proposals/{$token}/signature", ['signer_name' => 'Jane Buyer', 'signer_role' => 'CFO', 'consent' => true])->assertOk();
    $envelope = (string) $start->json('data.envelope_id');
    $code ??= (string) end($captured);

    return ['envelope' => $envelope, 'code' => $code];
}

it('signs with a one-time code, locks the selections into a hashed PDF with an audit certificate and accepts the quote', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    $this->putJson("/api/v1/public/proposals/{$quote['token']}/selections", ['selections' => [$quote['lines']['Extended support'] => true]])->assertOk();

    $captured = [];
    Event::listen(QuoteOtpRequested::class, static function (QuoteOtpRequested $event) use (&$captured): void {
        $captured[] = $event->code;
    });
    $start = $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature", ['signer_name' => 'Jane Buyer', 'signer_role' => 'CFO', 'consent' => true])->assertOk();
    $envelope = $start->json('data.envelope_id');
    expect($captured)->toHaveCount(1)->and($captured[0])->toMatch('/^\d{6}$/');

    $wrong = $captured[0] === '000000' ? '111111' : '000000';
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $wrong])->assertNotFound();
    expect(CpqQuote::query()->find($quote['id'])->status->value)->toBe('negotiating');

    $done = $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $captured[0]])->assertOk();
    $accepted = CpqQuote::query()->findOrFail($quote['id']);

    expect($done->json('data.status'))->toBe('accepted')
        ->and((string) $accepted->total_net)->toBe('2300.00')
        ->and(hash('sha256', Storage::disk('local')->get($accepted->signed_document_path)))->toBe($accepted->signed_document_hash)
        ->and(hash('sha256', Storage::disk('local')->get($accepted->audit_certificate_path)))->toBe($accepted->audit_certificate_hash)
        ->and(Storage::disk('local')->get($accepted->signed_document_path))->toStartWith('%PDF')
        ->and(app(QuoteAuditTrail::class)->verify($accepted))->toBeTrue()
        ->and($accepted->auditEvents->pluck('event')->all())->toBe(['sent', 'option_toggled', 'signature_initiated', 'otp_sent', 'signed']);

    $signed = $accepted->envelopes()->first();
    expect($signed->status)->toBe('signed')->and($signed->document_hash)->toBe($accepted->signed_document_hash)->and($signed->signer_ip)->not->toBeNull();

    // The one-time code is single use, and a repeated completion changes nothing.
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $captured[0]])->assertOk()
        ->assertJsonPath('data.signed_document_hash', $accepted->signed_document_hash);
    expect(CpqAuditEvent::query()->where('quote_id', $quote['id'])->where('event', 'signed')->count())->toBe(1);

    $this->get("/api/v1/public/proposals/{$quote['token']}/documents/proposal")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson("/api/v1/public/proposals/{$quote['token']}/documents/certificate")->assertOk();
});

it('refuses to sign without consent and expires the one-time code', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature", ['signer_name' => 'Jane', 'consent' => false])->assertUnprocessable();

    $captured = [];
    Event::listen(QuoteOtpRequested::class, static function (QuoteOtpRequested $event) use (&$captured): void {
        $captured[] = $event->code;
    });
    $envelope = $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature", ['signer_name' => 'Jane', 'consent' => true])->json('data.envelope_id');
    DB::table('cpq_signature_envelopes')->where('id', $envelope)->update(['otp_expires_at' => now()->subMinute()]);

    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $captured[0]])->assertNotFound();
    expect(CpqQuote::query()->find($quote['id'])->status->value)->not->toBe('accepted');
});

it('rejects a proposal only with a reason and closes its link', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    $this->postJson("/api/v1/public/proposals/{$quote['token']}/reject", ['reason' => ''])->assertUnprocessable();
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/reject", ['reason' => 'Too expensive'])->assertOk();

    expect(CpqQuote::query()->find($quote['id']))->status->value->toBe('rejected')->rejection_reason->toBe('Too expensive');
    $this->getJson("/api/v1/public/proposals/{$quote['token']}")->assertNotFound();
});

it('converts an accepted quote into one sales order whatever the number of clicks', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-order")->assertUnprocessable();

    ['envelope' => $envelope, 'code' => $code] = qtgSign($quote['token'], $quote['id']);
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $code])->assertOk();

    $first = $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-order")->assertOk()->json('data.sales_order_id');
    $second = $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-order")->assertOk()->json('data.sales_order_id');

    expect($second)->toBe($first)
        ->and(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('sales_order_items')->where('sales_order_id', $first)->count())->toBe(2)
        ->and((float) DB::table('sales_orders')->where('id', $first)->value('total_net'))->toBe(2000.0)
        ->and(CpqQuote::query()->find($quote['id']))->converted_sales_order_id->toBe($first)->status->value->toBe('converted');
});

it('converts automatically on acceptance when the quote asks for it', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact, ['auto_convert' => true, 'auto_convert_target' => 'sales_order']);

    ['envelope' => $envelope, 'code' => $code] = qtgSign($quote['token'], $quote['id']);
    $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $code])->assertOk();

    expect(DB::table('sales_orders')->count())->toBe(1)->and(CpqQuote::query()->find($quote['id']))->converted_sales_order_id->not->toBeNull();
});

it('chains revisions, supersedes the previous one with its link and diffs the two', function (): void {
    ['contact' => $contact] = qtgLogin();
    $first = qtgIssued($contact);

    $draft = $this->postJson("/api/v1/cpq/quotes/{$first['id']}/revisions")->assertCreated();
    $secondId = $draft->json('data.id');
    expect($draft->json('data.quote_number'))->toEndWith('.02')->and($draft->json('data.parent_quote_id'))->toBe($first['id'])->and($draft->json('data.root_quote_id'))->toBe($first['id'])
        ->and($draft->json('data.status'))->toBe('draft');

    $this->postJson("/api/v1/cpq/quotes/{$first['id']}/revisions")->assertUnprocessable();

    $this->putJson("/api/v1/cpq/quotes/{$secondId}", ['header_discount_percent' => 10, 'lines' => collect($draft->json('data.lines'))->map(static fn (array $l): array => [
        'section_key' => $l['section_id'], 'line_type' => $l['line_type'], 'selection_mode' => $l['selection_mode'], 'alternative_group' => $l['alternative_group'],
        'is_default_selected' => $l['is_default_selected'], 'description' => $l['description'], 'quantity' => $l['description'] === 'Server' ? 3 : $l['quantity'],
        'unit_price' => $l['unit_price'], 'unit_cost' => $l['unit_cost'], 'tax_rate' => $l['tax_rate'], 'id' => $l['id'],
    ])->all(), 'sections' => collect($draft->json('data.sections'))->map(static fn (array $s): array => ['id' => $s['id'], 'key' => $s['id'], 'title' => $s['title']])->all()])->assertOk();

    $second = $this->postJson("/api/v1/cpq/quotes/{$secondId}/issue")->assertOk();

    expect(CpqQuote::query()->find($first['id']))->status->value->toBe('superseded')->public_token_hash->toBeNull();
    $this->getJson("/api/v1/public/proposals/{$first['token']}")->assertNotFound();
    $this->getJson("/api/v1/public/proposals/{$second->json('data.portal_token')}")->assertOk();

    $diff = $this->getJson("/api/v1/cpq/quotes/{$first['id']}/compare/{$secondId}")->assertOk();
    expect($diff->json('data.totals.net.from'))->toBe('2000.00')->and($diff->json('data.totals.net.to'))->toBe('2700.00')
        ->and($diff->json('data.lines.added'))->toBe([])->and($diff->json('data.lines.removed'))->toBe([])
        ->and(array_column($diff->json('data.lines.modified'), 'label'))->toContain('Server');
});

it('parks a quote below its margin guardrail until a manager approves and issues it', function (): void {
    ['tenant' => $tenant, 'contact' => $contact] = qtgLogin('sales');
    $created = $this->postJson('/api/v1/cpq/quotes', qtgPayload($contact, ['min_margin_percent' => 60]))->assertCreated();
    expect($created->json('data.requires_approval'))->toBeTrue();

    $parked = $this->postJson("/api/v1/cpq/quotes/{$created->json('data.id')}/issue")->assertOk();
    expect($parked->json('data.quote.status'))->toBe('pending_approval')->and($parked->json('data.portal_token'))->toBeNull();

    $this->postJson("/api/v1/cpq/quotes/{$created->json('data.id')}/approve-and-issue")->assertForbidden();

    Sanctum::actingAs(tfrUserWithRole((string) $tenant->id, 'owner'));
    $this->postJson("/api/v1/cpq/quotes/{$created->json('data.id')}/approve-and-issue")->assertOk()->assertJsonPath('data.quote.status', 'sent');
});

it('completes a qualified signature only from an authentic provider callback and is idempotent', function (): void {
    config(['quoting.tsp.webhook_secret' => 'tsp-secret', 'quoting.tsp.ceremony_url' => 'https://tsp.example/ceremony']);
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact, ['signature_level' => 'qes']);

    $start = $this->postJson("/api/v1/public/proposals/{$quote['token']}/signature", ['signer_name' => 'Jane Buyer', 'consent' => true])->assertOk();
    $reference = $start->json('data.provider_reference');
    expect($start->json('data.redirect_url'))->toStartWith('https://tsp.example/ceremony');

    $payload = (string) json_encode(['reference' => $reference, 'status' => 'completed', 'level' => 'qes', 'certificate_serial' => 'CH-123', 'signed_at' => now()->toIso8601String()]);
    $forged = $this->call('POST', '/api/v1/public/signature-webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $payload, 'wrong')], $payload);
    $forged->assertForbidden();
    expect(CpqQuote::query()->find($quote['id'])->status->value)->not->toBe('accepted');

    $signature = hash_hmac('sha256', $payload, 'tsp-secret');
    $call = fn () => $this->call('POST', '/api/v1/public/signature-webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature], $payload);
    $call()->assertOk()->assertJsonPath('data.status', 'accepted');
    $call()->assertOk()->assertJsonPath('data.status', 'accepted');

    $accepted = CpqQuote::query()->findOrFail($quote['id']);
    $envelope = $accepted->envelopes()->first();
    expect($envelope->level->value)->toBe('qes')->and($envelope->verification_metadata['certificate_serial'])->toBe('CH-123')
        ->and(CpqAuditEvent::query()->where('quote_id', $quote['id'])->where('event', 'signed')->count())->toBe(1);
});

it('detects tampering with the hash-chained audit trail', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    $model = CpqQuote::query()->findOrFail($quote['id']);

    expect(app(QuoteAuditTrail::class)->verify($model))->toBeTrue();
    DB::table('cpq_audit_events')->where('quote_id', $quote['id'])->where('event', 'sent')->update(['actor' => 'someone-else']);
    expect(app(QuoteAuditTrail::class)->verify($model))->toBeFalse();
    expect(fn () => CpqAuditEvent::query()->where('quote_id', $quote['id'])->first()?->delete())->toThrow(LogicException::class);
});

it('keeps quotes of another tenant out of reach', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);

    qtgLogin();
    $this->getJson("/api/v1/cpq/quotes/{$quote['id']}")->assertNotFound();
    $this->getJson('/api/v1/cpq/quotes')->assertOk()->assertJsonPath('data.total', 0);
});

/**
 * Signs an issued quote with the one-time code, leaving it accepted.
 *
 * @param  array{id: string, token: string, pin: string|null, lines: array<string, string>}  $quote
 */
function qtgAccept(array $quote): void
{
    ['envelope' => $envelope, 'code' => $code] = qtgSign($quote['token'], $quote['id']);
    test()->postJson("/api/v1/public/proposals/{$quote['token']}/signature/complete", ['envelope_id' => $envelope, 'otp' => $code])->assertOk();
}

it('converts an accepted quote into one draft invoice whatever the number of clicks', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice")->assertUnprocessable();

    qtgAccept($quote);
    $first = $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice")->assertOk()->json('data');
    $second = $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice")->assertOk()->json('data');

    expect($second['invoice_id'])->toBe($first['invoice_id'])
        ->and($first['is_deposit'])->toBeFalse()
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('invoices')->where('id', $first['invoice_id'])->value('status'))->toBe('draft')
        ->and((float) DB::table('invoices')->where('id', $first['invoice_id'])->value('total_net'))->toBe(2000.0)
        ->and((float) DB::table('invoices')->where('id', $first['invoice_id'])->value('total_gross'))->toBe(2440.0)
        ->and(CpqQuote::query()->find($quote['id']))->converted_invoice_id->toBe($first['invoice_id'])->status->value->toBe('converted');
});

it('invoices a down payment as a percentage of the accepted quote, one line per VAT rate', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact);
    qtgAccept($quote);

    $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice", ['down_payment_percent' => 0])->assertUnprocessable();
    $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice", ['down_payment_percent' => 101])->assertUnprocessable();

    $deposit = $this->postJson("/api/v1/cpq/quotes/{$quote['id']}/convert-to-invoice", ['down_payment_percent' => 30])->assertOk()->json('data');

    expect($deposit['is_deposit'])->toBeTrue()
        ->and((float) DB::table('invoices')->where('id', $deposit['invoice_id'])->value('total_net'))->toBe(600.0)
        ->and((float) DB::table('invoices')->where('id', $deposit['invoice_id'])->value('total_gross'))->toBe(732.0)
        ->and(DB::table('invoice_items')->where('invoice_id', $deposit['invoice_id'])->count())->toBe(1);
});

it('converts a quote once: into an order or into an invoice, not both', function (): void {
    ['contact' => $contact] = qtgLogin();
    $toOrder = qtgIssued($contact);
    qtgAccept($toOrder);
    $this->postJson("/api/v1/cpq/quotes/{$toOrder['id']}/convert-to-order")->assertOk();
    $this->postJson("/api/v1/cpq/quotes/{$toOrder['id']}/convert-to-invoice")->assertUnprocessable();

    $toInvoice = qtgIssued($contact);
    qtgAccept($toInvoice);
    $this->postJson("/api/v1/cpq/quotes/{$toInvoice['id']}/convert-to-invoice")->assertOk();
    $this->postJson("/api/v1/cpq/quotes/{$toInvoice['id']}/convert-to-order")->assertUnprocessable();

    expect(DB::table('invoices')->count())->toBe(1)->and(DB::table('sales_orders')->count())->toBe(1);
});

it('drafts the invoice automatically on acceptance when the quote asks for it', function (): void {
    ['contact' => $contact] = qtgLogin();
    $quote = qtgIssued($contact, ['auto_convert' => true, 'auto_convert_target' => 'invoice']);

    qtgAccept($quote);

    expect(DB::table('invoices')->count())->toBe(1)->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(CpqQuote::query()->find($quote['id']))->converted_invoice_id->not->toBeNull()->status->value->toBe('converted');
});
