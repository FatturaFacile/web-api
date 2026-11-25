<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\LiquidityPlanning\Mail\LiquidityAlertMail;
use Modules\LiquidityPlanning\Models\LiquidityAlert;

require_once __DIR__.'/LiquiditySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    lpSetUp($this);
    Mail::fake();
});

it('raises a critical alert when the projected bank balance turns negative and emails it once within the cooldown', function (): void {
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['notify_emails' => ['cfo@example.com']]))->assertOk();
    lpBank($this, '100.00');
    lpManualItem($this, 'outflow', 'payable', '1000.00', 5);

    $alerts = $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk()->json('data');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['type'])->toBe('negative_balance')
        ->and($alerts[0]['severity'])->toBe('critical')
        ->and($alerts[0]['status'])->toBe('open')
        ->and($alerts[0]['title_key'])->toBe('lpApi.alert.negative_balance.title')
        ->and($alerts[0]['params']['date'])->toBe(now()->addDays(5)->toDateString());

    $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk();

    expect(LiquidityAlert::query()->count())->toBe(1);
    Mail::assertQueued(LiquidityAlertMail::class, 1);
});

it('raises the DSCR and the capital alerts and shows them in the overview', function (): void {
    lpManualItem($this, 'inflow', 'receivable', '500.00', 10);
    lpManualItem($this, 'outflow', 'debt_principal', '1000.00', 20);
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['jurisdiction' => 'CH', 'share_capital' => '100000', 'net_equity_override' => '40000']))->assertOk();

    $types = array_column($this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk()->json('data'), 'type');
    $overview = $this->getJson('/api/v1/liquidity-planning/overview')->assertOk()->json('data');

    expect($types)->toContain('dscr_below_threshold', 'capital_loss')
        ->and(array_column($overview['alerts'], 'type'))->toContain('dscr_below_threshold', 'capital_loss');
});

it('acknowledges an alert, keeps it while the condition lasts and resolves it when the condition ends', function (): void {
    $bank = lpBank($this, '100.00');
    $item = lpManualItem($this, 'outflow', 'payable', '1000.00', 5);

    $id = $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->json('data.0.id');
    $this->postJson("/api/v1/liquidity-planning/alerts/{$id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
    $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk()->assertJsonPath('data.0.status', 'acknowledged');

    treasuryInTenant($this->tenant, fn () => $bank->update(['current_balance' => '50000.00']));
    $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk()->assertJsonCount(0, 'data');

    $history = $this->getJson('/api/v1/liquidity-planning/alerts?status=resolved')->assertOk()->json('data');

    expect($history)->toHaveCount(1)->and($history[0]['resolved_at'])->not->toBeNull();
    expect($item->refresh()->is_active)->toBeTrue();
});

it('resolves an alert by hand', function (): void {
    lpBank($this, '100.00');
    lpManualItem($this, 'outflow', 'payable', '1000.00', 5);

    $id = $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->json('data.0.id');

    $this->postJson("/api/v1/liquidity-planning/alerts/{$id}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->getJson('/api/v1/liquidity-planning/alerts?status=open')->assertOk()->assertJsonCount(0, 'data');
});

it('sends no email when the email alerts are switched off', function (): void {
    $this->putJson('/api/v1/liquidity-planning/settings', lpSettingsPayload(['notify_emails' => ['cfo@example.com'], 'email_alerts_enabled' => false]))->assertOk();
    lpBank($this, '100.00');
    lpManualItem($this, 'outflow', 'payable', '1000.00', 5);

    $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->assertOk();

    Mail::assertNothingQueued();
});

it('keeps the alerts of each company apart', function (): void {
    lpBank($this, '100.00');
    lpManualItem($this, 'outflow', 'payable', '1000.00', 5);
    $id = $this->postJson('/api/v1/liquidity-planning/alerts/evaluate')->json('data.0.id');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/liquidity-planning/alerts')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/liquidity-planning/alerts/{$id}/acknowledge")->assertNotFound();
});
