<?php

declare(strict_types=1);

require_once __DIR__.'/MarketingTestHelpers.php';

use Database\Seeders\MarketingAutomationSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MarketingAutomation\Models\ConsentEvent;

const MA_BASE = '/api/v1/marketing-automation';

/**
 * @return array<string, mixed>
 */
function maCampaignPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Primavera 2026',
        'channel' => 'email_dem',
        'trigger_type' => 'scheduled_broadcast',
        'sender_name' => 'Mod155 Org SA',
        'sender_email' => 'info@example.ch',
        'sender_postal_address' => 'Via Test 1, 6900 Lugano',
        'provider' => 'smtp',
        'utm_campaign_code' => 'primavera-2026',
        'attribution_model' => 'first_touch',
        'attribution_window_days' => 14,
        'currency' => 'CHF',
        'campaign_cost' => 250,
        'template_mjml_json' => [
            'subject' => ['it' => 'Novità', 'de' => 'Neuigkeiten'],
            'blocks' => [['id' => 'h', 'type' => 'heading', 'props' => ['text' => ['it' => 'Ciao', 'de' => 'Hallo']]]],
        ],
    ], $overrides);
}

test('members can read marketing data but not change it; other roles are limited the same way', function (): void {
    [$tenant, $member] = maSetup('CH', 'member');

    maActingAs($member, $tenant)->getJson(MA_BASE.'/campaigns')->assertOk();
    maActingAs($member, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload())->assertForbidden();
    maActingAs($member, $tenant)->postJson(MA_BASE.'/segments', ['name' => 'X'])->assertForbidden();
    maActingAs($member, $tenant)->putJson(MA_BASE.'/consents/'.Str::uuid7(), ['marketing_opt_in' => true])->assertForbidden();
});

test('every endpoint requires authentication', function (): void {
    $this->getJson(MA_BASE.'/campaigns')->assertUnauthorized();
    $this->getJson(MA_BASE.'/dashboard')->assertUnauthorized();
    $this->postJson(MA_BASE.'/segments', ['name' => 'x'])->assertUnauthorized();
});

test('segments: create with criteria, live preview, evaluate and RFM matrix', function (): void {
    [$tenant, $user] = maSetup('CH');
    $customer = maContact($tenant);
    maInvoice($tenant, $customer, Carbon::now()->toDateString(), 1200);
    maConsent($tenant, $customer);
    $blocked = maContact($tenant);
    maInvoice($tenant, $blocked, Carbon::now()->toDateString(), 1200);
    maConsent($tenant, $blocked);
    DB::table('customer_credit_profiles')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $blocked, 'is_credit_hold' => true, 'created_at' => now(), 'updated_at' => now()]);

    $preview = maActingAs($user, $tenant)->postJson(MA_BASE.'/segments/preview', ['criteria' => ['exclude_insolvent' => true]])->assertOk();
    expect($preview->json('data.eligible_contacts_count'))->toBe(1)->and($preview->json('data.excluded_insolvent_count'))->toBe(1);

    $created = maActingAs($user, $tenant)->postJson(MA_BASE.'/segments', [
        'name' => 'Clienti attivi', 'description' => 'Test', 'auto_refresh_hourly' => true,
        'criteria' => ['recency_days_max' => 90, 'locale' => 'it', 'rfm_labels' => ['champions', 'loyal', 'promising', 'new_customers']],
    ])->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data.eligible_contacts_count'))->toBeGreaterThanOrEqual(0)
        ->and($created->json('data.auto_refresh_hourly'))->toBeTrue()
        ->and($created->json('data.last_evaluated_at'))->not->toBeNull();

    maActingAs($user, $tenant)->getJson(MA_BASE.'/segments')->assertOk()->assertJsonCount(1, 'data');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/segments/'.$id.'/evaluate')->assertOk()->assertJsonPath('data.id', $id);

    $matrix = maActingAs($user, $tenant)->getJson(MA_BASE.'/segments/'.$id.'/rfm-matrix')->assertOk();
    expect($matrix->json('data.labels'))->toHaveCount(7)->and($matrix->json('data.excluded_insolvent'))->toBe(1);

    maActingAs($user, $tenant)->putJson(MA_BASE.'/segments/'.$id, ['name' => 'Rinominato'])->assertOk()->assertJsonPath('data.name', 'Rinominato');
    maActingAs($user, $tenant)->deleteJson(MA_BASE.'/segments/'.$id)->assertOk();
    maActingAs($user, $tenant)->getJson(MA_BASE.'/segments/'.$id)->assertNotFound();
});

test('segment criteria are validated', function (): void {
    [$tenant, $user] = maSetup('CH');

    maActingAs($user, $tenant)->postJson(MA_BASE.'/segments', ['name' => 'Bad', 'criteria' => ['locale' => 'es', 'frequency_min' => -1, 'rfm_labels' => ['nope']]])
        ->assertStatus(422)->assertJsonValidationErrors(['criteria.locale', 'criteria.frequency_min', 'criteria.rfm_labels.0']);
    maActingAs($user, $tenant)->postJson(MA_BASE.'/segments', [])->assertStatus(422)->assertJsonValidationErrors(['name']);
});

test('campaigns: create, update, tenant isolation, delete only drafts', function (): void {
    [$tenant, $user] = maSetup('CH');
    [$other, $otherUser] = maSetup('IT');

    $created = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload())->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data.status'))->toBe('draft')
        ->and($created->json('data.attribution_model'))->toBe('first_touch')
        ->and($created->json('data.metric.roi_state'))->toBe('no_revenue')
        ->and($created->json('data.metric.campaign_cost'))->toBe(250.0);

    maActingAs($otherUser, $other)->getJson(MA_BASE.'/campaigns/'.$id)->assertNotFound();
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id, ['campaign_cost' => 0])->assertOk()->assertJsonPath('data.metric.roi_state', 'no_cost')->assertJsonPath('data.metric.calculated_roi_percentage', null);
    maActingAs($user, $tenant)->deleteJson(MA_BASE.'/campaigns/'.$id)->assertOk();
    maActingAs($user, $tenant)->getJson(MA_BASE.'/campaigns')->assertOk()->assertJsonCount(0, 'data');
});

test('campaign validation: enums, A/B split must total 100 %, UTM code, currency', function (): void {
    [$tenant, $user] = maSetup('CH');

    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['channel' => 'pigeon', 'trigger_type' => 'never', 'currency' => 'USD', 'utm_campaign_code' => 'bad code!', 'provider' => 'carrier']))
        ->assertStatus(422)->assertJsonValidationErrors(['channel', 'trigger_type', 'currency', 'utm_campaign_code', 'provider']);

    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['ab_test_split_variant_json' => ['variants' => [['key' => 'A', 'percent' => 60], ['key' => 'B', 'percent' => 30]]]]))
        ->assertStatus(422)->assertJsonValidationErrors(['ab_test_split_variant_json.variants']);

    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['ab_test_split_variant_json' => ['variants' => [['key' => 'A', 'percent' => 50], ['key' => 'B', 'percent' => 50]], 'winner_criteria' => 'click_rate']]))->assertCreated();
});

test('activation needs sender identity, an audience and a template; then it compiles the four locales', function (): void {
    [$tenant, $user] = maSetup('CH');
    $segment = maActingAs($user, $tenant)->postJson(MA_BASE.'/segments', ['name' => 'Tutti', 'criteria' => []])->json('data.id');

    $anonymous = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['sender_postal_address' => null, 'segment_id' => $segment]))->json('data.id');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$anonymous.'/activate')->assertStatus(422)->assertJsonPath('code', 'sender_identity_required');

    $noAudience = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload())->json('data.id');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$noAudience.'/activate')->assertStatus(422)->assertJsonPath('code', 'segment_required');

    $noTemplate = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['segment_id' => $segment, 'template_mjml_json' => null]))->json('data.id');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$noTemplate.'/activate')->assertStatus(422)->assertJsonPath('code', 'template_required');

    $ok = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['segment_id' => $segment]))->json('data.id');
    $active = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$ok.'/activate')->assertOk();

    expect($active->json('data.status'))->toBe('active')
        ->and($active->json('data.started_at'))->not->toBeNull()
        ->and(array_keys($active->json('data.template_mjml_json.compiled')))->toEqualCanonicalizing(['it', 'de', 'fr', 'en'])
        ->and($active->json('data.template_mjml_json.compiled.de'))->toContain('Hallo');
});

test('lifecycle: pause and resume, completed and archived campaigns are frozen', function (): void {
    [$tenant, $user] = maSetup('CH');
    $segment = maActingAs($user, $tenant)->postJson(MA_BASE.'/segments', ['name' => 'Tutti', 'criteria' => []])->json('data.id');
    $id = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['segment_id' => $segment]))->json('data.id');

    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/pause')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/schedule')->assertOk()->assertJsonPath('data.status', 'scheduled');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/activate')->assertOk();
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/pause')->assertOk()->assertJsonPath('data.status', 'paused');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
    maActingAs($user, $tenant)->deleteJson(MA_BASE.'/campaigns/'.$id)->assertStatus(409);
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns/'.$id.'/activate')->assertStatus(409);
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id, ['name' => 'x'])->assertStatus(409);
});

test('the visual journey builder persists nodes and edges and validates them', function (): void {
    [$tenant, $user] = maSetup('CH');
    $id = maActingAs($user, $tenant)->postJson(MA_BASE.'/campaigns', maCampaignPayload(['channel' => 'multi_channel_journey', 'trigger_type' => 'customer_dormant_60_days']))->json('data.id');

    $steps = [
        ['step_key' => 'mail', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'wait', 'pos_x' => 10, 'pos_y' => 20],
        ['step_key' => 'wait', 'type' => 'wait', 'config' => ['days' => 3], 'next_step_key' => 'check'],
        ['step_key' => 'check', 'type' => 'condition', 'config' => ['type' => 'clicked_not_ordered'], 'true_step_key' => 'task', 'false_step_key' => 'end'],
        ['step_key' => 'task', 'type' => 'crm_task', 'config' => ['subject' => ['it' => 'Chiama']], 'next_step_key' => 'end'],
        ['step_key' => 'end', 'type' => 'end'],
    ];

    $saved = maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => $steps])->assertOk();
    expect($saved->json('data.steps'))->toHaveCount(5)->and($saved->json('data.steps.0.pos_x'))->toBe(10);

    // Replacing the journey replaces every node.
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => [['step_key' => 'only', 'type' => 'send_whatsapp', 'is_entry' => true]]])->assertOk()->assertJsonCount(1, 'data.steps');
    maActingAs($user, $tenant)->getJson(MA_BASE.'/campaigns/'.$id)->assertOk()->assertJsonPath('data.steps.0.step_key', 'only');

    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => [['step_key' => 'a', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'ghost']]])->assertStatus(422)->assertJsonValidationErrors(['steps.0.next_step_key']);
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => [['step_key' => 'a', 'type' => 'send_email'], ['step_key' => 'b', 'type' => 'end']]])->assertStatus(422)->assertJsonValidationErrors(['steps']);
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => [['step_key' => 'a', 'type' => 'condition', 'is_entry' => true, 'true_step_key' => 'a']]])->assertStatus(422)->assertJsonValidationErrors(['steps.0.type']);
    maActingAs($user, $tenant)->putJson(MA_BASE.'/campaigns/'.$id.'/journey', ['steps' => [['step_key' => 'a', 'type' => 'teleport', 'is_entry' => true]]])->assertStatus(422)->assertJsonValidationErrors(['steps.0.type']);
});

test('template preview compiles per locale and rejects invalid MJML', function (): void {
    [$tenant, $user] = maSetup('CH');
    $template = ['subject' => ['it' => 'Ciao', 'de' => 'Hallo'], 'blocks' => [['type' => 'text', 'props' => ['text' => ['it' => 'Italiano', 'de' => 'Deutsch']]]]];

    $de = maActingAs($user, $tenant)->postJson(MA_BASE.'/template-preview', ['locale' => 'de', 'template' => $template])->assertOk();
    expect($de->json('data.subject'))->toBe('Hallo')->and($de->json('data.html'))->toContain('Deutsch');

    maActingAs($user, $tenant)->postJson(MA_BASE.'/template-preview', ['locale' => 'it', 'template' => ['mjml' => '<mjml><broken>']])->assertStatus(422)->assertJsonPath('code', 'invalid_mjml');
    maActingAs($user, $tenant)->postJson(MA_BASE.'/template-preview', ['locale' => 'es', 'template' => $template])->assertStatus(422);
});

test('blueprints serve the seeded presets, templates, journeys and texts of every locale', function (): void {
    [$tenant, $user] = maSetup('CH');
    (new MarketingAutomationSeeder)->run();

    $presets = maActingAs($user, $tenant)->getJson(MA_BASE.'/blueprints?kind=segment_preset')->assertOk();
    $names = collect($presets->json('data'))->map(fn (array $row): string => $row['texts']['it']['name'])->all();

    expect($names)->toContain('Campioni', 'Fedeli', 'A Rischio Abbandono', 'Persi', 'Clienti Dormienti 60gg Senza Insoluti', 'Kit Ricambi Macchinari 11 Mesi');
    maActingAs($user, $tenant)->getJson(MA_BASE.'/blueprints?kind=email_template')->assertOk()->assertJsonCount(3, 'data');
    maActingAs($user, $tenant)->getJson(MA_BASE.'/blueprints?kind=journey')->assertOk()->assertJsonCount(4, 'data');
});

test('the ERP catalog lookup returns product, price and image for the designer', function (): void {
    [$tenant, $user] = maSetup('CH');
    DB::table('products')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'sku' => 'KIT-1', 'name' => 'Kit ricambi A', 'description' => 'Guarnizioni e filtri', 'sale_price' => 249.9, 'is_active' => true, 'attributes' => json_encode(['image_url' => 'https://cdn.example.ch/kit.png']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('products')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'sku' => 'OLD', 'name' => 'Kit vecchio', 'sale_price' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

    $rows = maActingAs($user, $tenant)->getJson(MA_BASE.'/catalog/products?q=KIT')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sku'])->toBe('KIT-1')
        ->and($rows[0]['price'])->toBe(249.9)
        ->and($rows[0]['currency'])->toBe('CHF')
        ->and($rows[0]['image_url'])->toBe('https://cdn.example.ch/kit.png');
    expect(maActingAs($user, $tenant)->getJson(MA_BASE.'/catalog/products?q=zzz')->json('data'))->toBe([]);
});

test('consent changes are audited with IP, user agent and strategy; the ledger is append-only', function (): void {
    [$tenant, $user] = maSetup('IT');
    $contact = maContact($tenant, ['country' => 'IT']);

    $response = maActingAs($user, $tenant)->withHeader('User-Agent', 'BackofficeTest/1.0')->putJson(MA_BASE.'/consents/'.$contact, [
        'marketing_opt_in' => true, 'profiling_opt_in' => true, 'double_opt_in_confirmed' => true, 'channel_preferences' => ['email' => true, 'whatsapp' => false], 'locale' => 'de', 'source' => 'web_form',
    ])->assertOk();

    expect($response->json('data.marketing_opt_in'))->toBeTrue()->and($response->json('data.locale'))->toBe('de');

    $events = maActingAs($user, $tenant)->getJson(MA_BASE.'/consent-events?contact_id='.$contact)->assertOk()->json('data');
    $types = array_column($events, 'event_type');

    expect($types)->toContain('opt_in', 'double_opt_in_confirmed', 'profiling_opt_in')
        ->and($events[0]['strategy_code'])->toBe('it_gdpr_art130')
        ->and($events[0]['user_agent'])->toBe('BackofficeTest/1.0')
        ->and($events[0]['source'])->toBe('web_form')
        ->and($events[0]['ip_address'])->not->toBeNull()
        ->and($events[0]['lawful_basis'])->toBe('consent');

    // Withdrawal is a new event, never an edit.
    maActingAs($user, $tenant)->putJson(MA_BASE.'/consents/'.$contact, ['marketing_opt_in' => false])->assertOk();
    $after = ConsentEvent::withoutGlobalScopes()->where('contact_id', $contact)->pluck('event_type')->all();
    expect($after)->toContain('opt_out')->and(count($after))->toBeGreaterThan(count($events));

    $event = ConsentEvent::withoutGlobalScopes()->where('contact_id', $contact)->first();
    expect(fn () => $event->forceFill(['source' => 'tampered'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    maActingAs($user, $tenant)->getJson(MA_BASE.'/consents/'.$contact)->assertOk()->assertJsonPath('data.channel_preferences.whatsapp', false);
});

test('giving marketing consent again lifts an earlier unsubscribe and the ledger keeps both facts', function (): void {
    [$tenant, $user] = maSetup('CH');
    $contact = maContact($tenant);
    maConsent($tenant, $contact, ['unsubscribed_at' => now(), 'marketing_opt_in' => false]);

    maActingAs($user, $tenant)->putJson(MA_BASE.'/consents/'.$contact, ['marketing_opt_in' => true])->assertOk()->assertJsonPath('data.unsubscribed_at', null);

    expect(ConsentEvent::withoutGlobalScopes()->where('contact_id', $contact)->pluck('event_type')->all())->toContain('resubscribe', 'opt_in');
});

test('consents of a contact of another tenant cannot be touched', function (): void {
    [$tenant, $user] = maSetup('CH');
    [$other] = maSetup('CH');
    $foreign = maContact($other);

    maActingAs($user, $tenant)->putJson(MA_BASE.'/consents/'.$foreign, ['marketing_opt_in' => true])->assertNotFound();
    expect(DB::table('ma_consents')->where('contact_id', $foreign)->exists())->toBeFalse();
});

test('the compliance overview lists both strategies with their required footer texts', function (): void {
    [$tenant, $user] = maSetup('CH');

    $data = maActingAs($user, $tenant)->getJson(MA_BASE.'/compliance/strategies')->assertOk()->json('data');

    expect(array_column($data, 'code'))->toBe(['ch_lcsl_art3_nlpd', 'it_gdpr_art130'])
        ->and($data[0]['jurisdictions'])->toBe(['CH'])
        ->and($data[0]['footer_keys'])->toContain('footer.unsubscribe', 'footer.privacy_ch')
        ->and($data[1]['footer_keys'])->toContain('footer.privacy_it');
});

test('the dashboard exposes the KPIs per campaign and in total', function (): void {
    [$tenant, $user] = maSetup('CH');
    $campaign = maCampaign($tenant, ['campaign_cost' => 100]);
    $contact = maContact($tenant);
    maMessage($tenant, $campaign, $contact, now()->subDays(2), now()->subDay());
    $order = maSalesOrder($tenant, $contact, now()->toDateString(), 800);
    maOrderLine($tenant, $order, null, 1, 800, 300);
    app(Modules\MarketingAutomation\Services\AttributionService::class)->attribute((string) $tenant->id, 'sales_order', $order);

    $data = maActingAs($user, $tenant)->getJson(MA_BASE.'/dashboard')->assertOk()->json('data');

    expect($data['currency'])->toBe('CHF')
        ->and($data['campaigns'])->toHaveCount(1)
        ->and($data['totals']['sent_count'])->toBe(1)
        ->and($data['totals']['unique_clicks_count'])->toBe(1)
        ->and($data['totals']['attributed_net_revenue_amount'])->toBe(800.0)
        ->and($data['totals']['attributed_gross_margin_amount'])->toBe(500.0)
        ->and($data['totals']['calculated_roi_percentage'])->toBe(400.0)
        ->and($data['campaigns'][0]['roi_state'])->toBe('computed');

    $logs = maActingAs($user, $tenant)->getJson(MA_BASE.'/campaigns/'.$campaign->id.'/logs')->assertOk();
    expect($logs->json('data'))->toBe([]);
});

test('A/B statistics name the winner by the configured criterion', function (): void {
    [$tenant, $user] = maSetup('CH');
    $campaign = maCampaign($tenant, ['ab_test_split_variant_json' => ['variants' => [['key' => 'A', 'percent' => 50], ['key' => 'B', 'percent' => 50]], 'winner_criteria' => 'click_rate']]);

    foreach ([['A', false], ['A', false], ['B', true], ['B', false]] as [$variant, $clicked]) {
        $contact = maContact($tenant);
        maMessage($tenant, $campaign, $contact, now()->subDay(), $clicked ? now() : null)->forceFill(['variant' => $variant])->save();
    }

    $stats = maActingAs($user, $tenant)->getJson(MA_BASE.'/campaigns/'.$campaign->id.'/ab-stats')->assertOk()->json('data');

    expect($stats['criteria'])->toBe('click_rate')
        ->and($stats['winner'])->toBe('B')
        ->and($stats['variants'][0]['variant'])->toBe('B')
        ->and($stats['variants'][0]['click_rate'])->toBe(50.0);
});
