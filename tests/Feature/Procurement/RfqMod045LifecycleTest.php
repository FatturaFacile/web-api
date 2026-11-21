<?php

declare(strict_types=1);

use App\Domain\Procurement\Rfq\Enums\BidStatus;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Infrastructure\Procurement\Rfq\Mail\RfqAwardNotificationMail;
use App\Infrastructure\Procurement\Rfq\Mail\RfqCancelledMail;
use App\Infrastructure\Procurement\Rfq\Mail\RfqDeadlineExtendedMail;
use App\Infrastructure\Procurement\Rfq\Mail\RfqInvitationMail;
use App\Infrastructure\Procurement\Rfq\Mail\RfqRegretNotificationMail;
use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\Supplier;

require_once __DIR__.'/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2027-06-01 09:00:00');
    $this->tenant = procTenant('Manifattura Cross-Border');
    $this->otherTenant = procTenant('Altra Azienda SA');
    $this->buyer = procMember($this->tenant, 'admin');
    procActAs($this->buyer, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function mod045SupplierWithEmail(string $name, string $email, string $country = 'IT', string $rating = 'A'): string
{
    $supplierId = procSupplier($name);
    Contact::query()->whereKey($supplierId)->update(['email' => $email, 'country' => $country]);
    Supplier::query()->whereKey($supplierId)->update(['rating' => $rating]);

    return $supplierId;
}

test('buyer imports multiple approved PR lines, attaches specs, publishes with hashed tokens, and extends deadline', function (): void {
    $alfa = mod045SupplierWithEmail('Alfa Meccanica', 'alfa@example.it', 'IT', 'A');
    $beta = mod045SupplierWithEmail('Beta Precision AG', 'beta@example.ch', 'CH', 'B');

    $pr1 = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Reparto Tornitura',
        'required_by_date' => '2027-07-01',
        'urgency' => 'medium',
        'currency' => 'EUR',
        'lines' => [
            ['description' => 'Cuscinetto SKF 6204', 'quantity_requested' => '10', 'uom' => 'PCS', 'estimated_unit_price' => '15.00'],
            ['description' => 'Albero rettificato 20mm', 'quantity_requested' => '4', 'uom' => 'PCS', 'estimated_unit_price' => '80.00'],
        ],
    ])->assertCreated()->json('id');
    $this->postJson("/api/v1/procurement/requisitions/{$pr1}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    $pr2 = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Manutenzione Linea 2',
        'required_by_date' => '2027-07-05',
        'urgency' => 'high',
        'currency' => 'EUR',
        'lines' => [
            ['description' => 'Guarnizione Viton 40mm', 'quantity_requested' => '25', 'uom' => 'PCS', 'estimated_unit_price' => '4.00'],
        ],
    ])->assertCreated()->json('id');
    $this->postJson("/api/v1/procurement/requisitions/{$pr2}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    $rfq = $this->postJson('/api/v1/procurement/rfqs/from-requisitions', [
        'purchase_requisition_ids' => [$pr1, $pr2],
        'title' => 'Fornitura Componenti Giugno',
        'description' => 'Componenti meccanici per produzione estiva',
        'currency_code' => 'EUR',
        'incoterms' => 'DDP',
        'submission_deadline' => '2027-06-10T17:00:00Z',
        'expected_delivery_date' => '2027-07-01',
        'sealed_bidding' => false,
        'evaluation_weights' => ['price_weight' => 50, 'lead_time_weight' => 30, 'supplier_rating_weight' => 20],
        'supplier_ids' => [$alfa, $beta],
    ])->assertCreated()
        ->assertJsonPath('data.status', RfqStatus::Draft->value)
        ->assertJsonCount(3, 'data.lines')
        ->json('data');

    expect(PurchaseRequisition::find($pr1)->status)->toBe('sourcing')
        ->and(PurchaseRequisition::find($pr2)->status)->toBe('sourcing')
        ->and(PurchaseRequisitionLine::query()->where('purchase_requisition_id', $pr1)->first()->sourcing_status)->toBe('in_quotation');

    // Upload visible and internal-only attachments
    $visibleAtt = $this->post('/api/v1/procurement/rfqs/'.$rfq['id'].'/attachments', [
        'file' => UploadedFile::fake()->create('drawing.pdf', 32, 'application/pdf'),
        'is_visible_to_suppliers' => true,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    $hiddenAtt = $this->post('/api/v1/procurement/rfqs/'.$rfq['id'].'/attachments', [
        'file' => UploadedFile::fake()->create('internal-cost-sheet.xlsx', 16),
        'is_visible_to_suppliers' => false,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    // Publish RFQ: tokens generated, only SHA-256 hashes persisted, emails queued
    $this->postJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/publish')
        ->assertOk()
        ->assertJsonPath('data.status', RfqStatus::BiddingOpen->value);

    Mail::assertQueued(RfqInvitationMail::class, 2);
    $mails = Mail::queued(RfqInvitationMail::class);
    $tokensByEmail = [];
    foreach ($mails as $mail) {
        $tokensByEmail[$mail->to[0]['address']] = $mail->token;
        // Plaintext token must NEVER be stored in DB
        expect(RfqSupplierInvitation::query()->where('access_token_hash', $mail->token)->exists())->toBeFalse()
            ->and(RfqSupplierInvitation::query()->where('access_token_hash', hash('sha256', $mail->token))->exists())->toBeTrue();
    }

    // Extend deadline
    $this->postJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/extend-deadline', [
        'submission_deadline' => '2027-06-15T17:00:00Z',
    ])->assertOk();

    Mail::assertQueued(RfqDeadlineExtendedMail::class, 2);

    // Public portal access: invalid token -> 401
    $this->flushHeaders();
    $this->getJson('/api/v1/public/rfq-portal/'.Str::random(64))->assertUnauthorized();

    // Valid token strips target_unit_price & hidden attachments
    $alfaToken = $tokensByEmail['alfa@example.it'];
    $portal = $this->getJson("/api/v1/public/rfq-portal/{$alfaToken}")
        ->assertOk()
        ->assertJsonCount(1, 'data.rfq.attachments')
        ->json('data');

    expect($portal['rfq']['lines'][0])->not->toHaveKey('target_unit_price')
        ->and($portal['invitation']['status'])->toBe(InvitationStatus::Viewed->value);

    // Visible attachment streams cleanly; hidden attachment returns 404
    $this->get("/api/v1/public/rfq-portal/{$alfaToken}/attachments/{$visibleAtt['id']}")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get("/api/v1/public/rfq-portal/{$alfaToken}/attachments/{$hiddenAtt['id']}")
        ->assertNotFound();

    // Expired token -> 410 Gone
    $this->travelTo('2027-06-16 09:00:00');
    $this->getJson("/api/v1/public/rfq-portal/{$alfaToken}")->assertStatus(410);
});

test('sealed bidding blocks price viewing and award before deadline, then unlocks and executes split award with multiple POs', function (): void {
    $alfa = mod045SupplierWithEmail('Alfa Steel', 'alfa@steel.it', 'IT', 'A');
    $beta = mod045SupplierWithEmail('Beta Swiss GmbH', 'beta@swiss.ch', 'CH', 'A');
    $gamma = mod045SupplierWithEmail('Gamma Logistics', 'gamma@fr.eu', 'FR', 'B');

    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'source' => 'CHF',
        'target' => 'EUR',
        'rate' => '1.100000',
        'effective_date' => '2027-06-01',
    ]);

    $prId = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Split Award PR',
        'required_by_date' => '2027-07-10',
        'urgency' => 'medium',
        'currency' => 'EUR',
        'lines' => [
            ['description' => 'Motore 5kW', 'quantity_requested' => '2', 'uom' => 'PCS', 'estimated_unit_price' => '500.00'],
            ['description' => 'Inverter 5kW', 'quantity_requested' => '2', 'uom' => 'PCS', 'estimated_unit_price' => '400.00'],
        ],
    ])->assertCreated()->json('id');
    $this->postJson("/api/v1/procurement/requisitions/{$prId}/submit-approval")->assertOk();

    $rfq = $this->postJson('/api/v1/procurement/rfqs/from-requisitions', [
        'purchase_requisition_ids' => [$prId],
        'title' => 'Motori e Inverter',
        'currency_code' => 'EUR',
        'submission_deadline' => '2027-06-10T12:00:00Z',
        'sealed_bidding' => true,
        'evaluation_weights' => ['price_weight' => 70, 'lead_time_weight' => 20, 'supplier_rating_weight' => 10],
        'supplier_ids' => [$alfa, $beta, $gamma],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/publish')->assertOk();

    $tokens = [];
    foreach (Mail::queued(RfqInvitationMail::class) as $mail) {
        $tokens[$mail->to[0]['address']] = $mail->token;
    }

    $line1Id = $rfq['lines'][0]['id'];
    $line2Id = $rfq['lines'][1]['id'];

    // Alfa submits draft then final bid: cheaper on Line 1 (420 EUR)
    $this->flushHeaders();
    $this->postJson('/api/v1/public/rfq-portal/'.$tokens['alfa@steel.it'].'/bid', [
        'action' => 'draft',
        'currency_code' => 'EUR',
        'lines' => [
            ['rfq_line_id' => $line1Id, 'unit_price' => '420.00', 'lead_time_days' => 5],
            ['rfq_line_id' => $line2Id, 'unit_price' => '450.00', 'lead_time_days' => 5],
        ],
    ])->assertOk()->assertJsonPath('data.status', BidStatus::Draft->value);

    $alfaBid = $this->post('/api/v1/public/rfq-portal/'.$tokens['alfa@steel.it'].'/bid', [
        'action' => 'submit',
        'supplier_quote_reference' => 'ALFA-001',
        'currency_code' => 'EUR',
        'payment_terms_days' => 60,
        'incoterms' => 'DDP',
        'shipping_cost' => '25.00',
        'valid_until' => '2027-07-30',
        'quote_attachment' => UploadedFile::fake()->create('alfa-quote.pdf', 20, 'application/pdf'),
        'lines' => json_encode([
            ['rfq_line_id' => $line1Id, 'unit_price' => '420.00', 'discount_percent' => '0', 'lead_time_days' => 5],
            ['rfq_line_id' => $line2Id, 'unit_price' => '450.00', 'discount_percent' => '0', 'lead_time_days' => 10],
        ]),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', BidStatus::Submitted->value)->json('data');

    // Beta submits in CHF (normalized at 1.10): cheaper on Line 2 (300 CHF = 330 EUR)
    $betaBid = $this->postJson('/api/v1/public/rfq-portal/'.$tokens['beta@swiss.ch'].'/bid', [
        'action' => 'submit',
        'supplier_quote_reference' => 'BETA-CH-9',
        'currency_code' => 'CHF',
        'payment_terms_days' => 30,
        'valid_until' => '2027-07-30',
        'lines' => [
            ['rfq_line_id' => $line1Id, 'unit_price' => '480.00', 'lead_time_days' => 7],
            ['rfq_line_id' => $line2Id, 'unit_price' => '300.00', 'lead_time_days' => 3],
        ],
    ])->assertOk()->assertJsonPath('data.exchange_rate_to_rfq', '1.100000')->json('data');

    // Gamma submits a higher bid on both lines (not awarded -> receives regret email)
    $this->postJson('/api/v1/public/rfq-portal/'.$tokens['gamma@fr.eu'].'/bid', [
        'action' => 'submit',
        'currency_code' => 'EUR',
        'lines' => [
            ['rfq_line_id' => $line1Id, 'unit_price' => '550.00', 'lead_time_days' => 12],
            ['rfq_line_id' => $line2Id, 'unit_price' => '550.00', 'lead_time_days' => 12],
        ],
    ])->assertOk();

    // Buyer checks comparison matrix BEFORE deadline: prices MUST be redacted
    procActAs($this->buyer, $this->tenant);
    $sealedMatrix = $this->getJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/comparison-matrix')
        ->assertOk()
        ->assertJsonPath('data.is_sealed', true)
        ->assertJsonPath('data.can_award', false)
        ->json('data');
    expect($sealedMatrix['columns'][0]['total_landed_cost'])->toBeNull()
        ->and($sealedMatrix['rows'][0]['cells'])->toBe([]);

    // Award BEFORE deadline must be rejected (422)
    $this->postJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/award', [
        'award_mode' => 'full',
        'bid_id' => $alfaBid['id'],
    ])->assertUnprocessable()->assertJsonValidationErrors('sealed_bidding');

    // Travel past deadline -> matrix unsealed & Split Award succeeds (2 POs generated!)
    $this->travelTo('2027-06-10 13:00:00');
    $matrix = $this->getJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/comparison-matrix')
        ->assertOk()
        ->assertJsonPath('data.is_sealed', false)
        ->assertJsonPath('data.can_award', true)
        ->json('data');

    $alfaLine1BidLineId = collect($alfaBid['lines'])->firstWhere('rfq_line_id', $line1Id)['id'];
    $betaLine2BidLineId = collect($betaBid['lines'])->firstWhere('rfq_line_id', $line2Id)['id'];

    expect($matrix['recommendations']['optimal_split_line_awards'])->toHaveCount(2);

    $awardResponse = $this->postJson('/api/v1/procurement/rfqs/'.$rfq['id'].'/award', [
        'award_mode' => 'split',
        'line_awards' => [
            ['rfq_line_id' => $line1Id, 'supplier_bid_line_id' => $alfaLine1BidLineId],
            ['rfq_line_id' => $line2Id, 'supplier_bid_line_id' => $betaLine2BidLineId],
        ],
    ])->assertCreated()->json('data');

    expect($awardResponse['rfq']['status'])->toBe(RfqStatus::Awarded->value)
        ->and($awardResponse['rfq']['award_mode'])->toBe('split')
        ->and($awardResponse['purchase_orders'])->toHaveCount(2)
        ->and(PurchaseOrder::query()->where('rfq_id', $rfq['id'])->count())->toBe(2)
        ->and(PurchaseRequisition::find($prId)->status)->toBe('converted_to_po');

    Mail::assertQueued(RfqAwardNotificationMail::class, 2);
    Mail::assertQueued(RfqRegretNotificationMail::class, 1);
});

test('cancelling an open RFQ releases locked PR lines, expires tokens, and notifies suppliers', function (): void {
    $alfa = mod045SupplierWithEmail('Alfa Cancel', 'alfa-cancel@example.it', 'IT');
    $prId = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Cancel PR',
        'required_by_date' => '2027-07-01',
        'urgency' => 'low',
        'lines' => [
            ['description' => 'Valvola PN16', 'quantity_requested' => '10', 'uom' => 'PCS', 'estimated_unit_price' => '20.00'],
        ],
    ])->assertCreated()->json('id');
    $this->postJson("/api/v1/procurement/requisitions/{$prId}/submit-approval")->assertOk();

    $rfqId = $this->postJson('/api/v1/procurement/rfqs/from-requisitions', [
        'purchase_requisition_ids' => [$prId],
        'title' => 'Gara Annullata',
        'submission_deadline' => '2027-06-10T17:00:00Z',
        'supplier_ids' => [$alfa],
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/publish")->assertOk();
    $token = Mail::queued(RfqInvitationMail::class)->last()->token;

    $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/cancel", ['reason' => 'Project postponed'])
        ->assertOk()
        ->assertJsonPath('data.status', RfqStatus::Cancelled->value);

    expect(PurchaseRequisition::find($prId)->status)->toBe('approved')
        ->and(PurchaseRequisitionLine::query()->where('purchase_requisition_id', $prId)->first()->sourcing_status)->toBe('open');

    Mail::assertQueued(RfqCancelledMail::class, 1);

    $this->flushHeaders();
    $this->getJson("/api/v1/public/rfq-portal/{$token}")->assertStatus(410);
});

test('multi-tenant isolation blocks cross-tenant RFQ reads, updates, and foreign PR imports', function (): void {
    $alfa = mod045SupplierWithEmail('Tenant One Supplier', 't1@example.it');
    $rfqId = $this->postJson('/api/v1/procurement/rfqs', [
        'title' => 'Tenant 1 RFQ',
        'submission_deadline' => '2027-06-20T12:00:00Z',
        'supplier_ids' => [$alfa],
        'lines' => [['description' => 'Part A', 'quantity' => '5', 'unit_of_measure' => 'PCS']],
    ])->assertCreated()->json('data.id');

    $foreignBuyer = procMember($this->otherTenant, 'admin');
    procActAs($foreignBuyer, $this->otherTenant);

    $this->getJson("/api/v1/procurement/rfqs/{$rfqId}")->assertNotFound();
    $this->getJson("/api/v1/procurement/rfqs/{$rfqId}/comparison-matrix")->assertNotFound();
    $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/publish")->assertNotFound();
    $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/cancel")->assertNotFound();
});
