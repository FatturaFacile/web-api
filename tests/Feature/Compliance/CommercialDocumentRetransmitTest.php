<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\FiscalRetail\Contracts\CorrispettiviGateway;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Gateways\LocalCorrispettiviGateway;
use Modules\FiscalRetail\Models\CommercialDocument;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-04-15 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

/**
 * A channel that refuses the first call and accepts the next ones, keeping the idempotency keys it was given.
 */
function channelDownOnce(): object
{
    $gateway = new class implements CorrispettiviGateway
    {
        /** @var list<string> */
        public array $keys = [];

        public int $failures = 1;

        public function name(): string
        {
            return 'local';
        }

        public function assertAvailable(): void {}

        public function sendDocument(Tenant $tenant, CommercialDocument $document, string $idempotencyKey): array
        {
            $this->keys[] = $idempotencyKey;

            if ($this->failures-- > 0) {
                throw new CorrispettiviGatewayException('Channel unreachable');
            }

            return ['status' => 'accepted', 'reference' => 'REF-'.$idempotencyKey];
        }

        public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows, string $idempotencyKey): array
        {
            return ['status' => 'accepted', 'reference' => 'DAY-'.$idempotencyKey];
        }
    };
    app()->instance(LocalCorrispettiviGateway::class, $gateway);

    return $gateway;
}

function retransmitSale(): array
{
    return test()->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'Pane', 'quantity' => 1, 'unit_price' => '10.00', 'tax_rate' => 22]]])->assertCreated()->json('data');
}

test('a failed document is listed by status and sent again with the same idempotency key', function (): void {
    $gateway = channelDownOnce();
    $document = retransmitSale();
    $ok = retransmitSale();

    $failed = $this->getJson('/api/v1/fiscal-retail/commercial-documents?gateway_status=failed')->assertOk()->json('data');
    expect($failed)->toHaveCount(1)->and($failed[0]['id'])->toBe($document['id']);

    $sent = $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/retransmit")->assertOk()->json('data');

    expect($sent['gateway_status'])->toBe('accepted')->and($sent['gateway_error'])->toBeNull()
        ->and($gateway->keys[0])->toBe($gateway->keys[2])
        ->and($this->getJson('/api/v1/fiscal-retail/commercial-documents?gateway_status=failed')->json('data'))->toBe([]);

    // Nothing failed any more, so there is nothing to send again.
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/retransmit")->assertUnprocessable()->assertJsonValidationErrors('document');
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$ok['id']}/retransmit")->assertUnprocessable();
});

test('retransmission is tenant scoped and closed to read-only roles', function (): void {
    channelDownOnce();
    $document = retransmitSale();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/retransmit")->assertForbidden();

    actingAsMember(complianceTenant('Beta'));
    $this->postJson("/api/v1/fiscal-retail/commercial-documents/{$document['id']}/retransmit")->assertNotFound();
    $this->postJson('/api/v1/fiscal-retail/commercial-documents/'.Str::uuid().'/retransmit')->assertNotFound();
    expect(CommercialDocument::withoutGlobalScopes()->whereKey($document['id'])->value('gateway_status'))->toBe('failed');
});
