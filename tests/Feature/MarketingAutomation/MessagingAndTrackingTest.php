<?php

declare(strict_types=1);

require_once __DIR__.'/MarketingTestHelpers.php';

use Database\Seeders\MarketingAutomationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\MarketingAutomation\Domain\Enums\SuppressionReason;
use Modules\MarketingAutomation\Jobs\SendMarketingMessageJob;
use Modules\MarketingAutomation\Models\ConsentEvent;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Models\MarketingMessage;
use Modules\MarketingAutomation\Models\Suppression;
use Modules\MarketingAutomation\Services\Delivery\OutboundEmail;
use Modules\MarketingAutomation\Services\Delivery\SmtpMailAdapter;
use Modules\MarketingAutomation\Services\MarketingMessageService;
use Modules\MarketingAutomation\Services\MetricsService;
use Modules\MarketingAutomation\Services\SuppressionService;
use Modules\MarketingAutomation\Services\TrackingService;

/**
 * Captures what would leave through the provider instead of sending it.
 */
final class MaCapturingSmtpAdapter extends SmtpMailAdapter
{
    /** @var list<OutboundEmail> */
    public array $sent = [];

    public function send(OutboundEmail $email, array $config): array
    {
        $this->sent[] = $email;

        return ['reference' => 'ref-'.count($this->sent)];
    }
}

function maCaptureMail(): MaCapturingSmtpAdapter
{
    $adapter = new MaCapturingSmtpAdapter;
    app()->instance(SmtpMailAdapter::class, $adapter);

    return $adapter;
}

function maMailable(string $country = 'CH', array $consent = []): array
{
    [$tenant] = maSetup($country);
    (new MarketingAutomationSeeder)->run();
    $contact = maContact($tenant, ['company_name' => 'Rossi SA', 'first_name' => 'Mario']);
    maConsent($tenant, $contact, $consent);

    $campaign = maCampaign($tenant, ['utm_campaign_code' => 'spring', 'template_mjml_json' => [
        'subject' => ['it' => 'Ciao {{first_name}}'],
        'blocks' => [
            ['type' => 'text', 'props' => ['text' => ['it' => 'Offerta per {{company_name}}']]],
            ['type' => 'button', 'props' => ['href' => 'https://shop.example.ch/offer', 'label' => ['it' => 'Vedi']]],
        ],
    ]]);

    return [$tenant, $contact, $campaign];
}

test('prepare refuses recipients without consent, unsubscribed, suppressed, insolvent or without an address', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $service = app(MarketingMessageService::class);

    expect($service->prepare($campaign, $contact)['message'])->not->toBeNull();

    $stranger = maContact($tenant);
    expect($service->prepare($campaign, $stranger)['reason'])->toBe('no_consent');

    $noMail = maContact($tenant, ['email' => '']);
    maConsent($tenant, $noMail);
    expect($service->prepare($campaign, $noMail)['reason'])->toBe('no_address');

    $unsubscribed = maContact($tenant);
    maConsent($tenant, $unsubscribed, ['unsubscribed_at' => now()]);
    expect($service->prepare($campaign, $unsubscribed)['reason'])->toBe('unsubscribed');

    $suppressed = maContact($tenant, ['email' => 'gone@example.ch']);
    maConsent($tenant, $suppressed);
    app(SuppressionService::class)->suppress((string) $tenant->id, 'GONE@example.ch', SuppressionReason::HardBounce, $suppressed);
    expect($service->prepare($campaign, $suppressed)['reason'])->toBe('suppressed');

    $insolvent = maContact($tenant);
    maConsent($tenant, $insolvent);
    DB::table('customer_credit_profiles')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'customer_id' => $insolvent, 'is_credit_hold' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect($service->prepare($campaign, $insolvent)['reason'])->toBe('credit_blocked');

    $anonymous = maCampaign($tenant, ['sender_postal_address' => '']);
    expect($service->prepare($anonymous, $contact)['reason'])->toBe('sender_not_identified');
});

test('Italian soft spam works for e-mail to existing customers but never for WhatsApp', function (): void {
    [$tenant, , $campaign] = maMailable('IT');
    $customer = maContact($tenant);
    maInvoice($tenant, $customer, now()->subMonth()->toDateString(), 100);
    maConsent($tenant, $customer, ['marketing_opt_in' => false, 'soft_opt_in_eligible' => true]);
    $service = app(MarketingMessageService::class);

    expect($service->prepare($campaign, $customer, null, 'email')['message'])->not->toBeNull()
        ->and($service->prepare($campaign, $customer, null, 'whatsapp')['reason'])->toBe('no_consent');
});

test('the e-mail leaves with sender identity, one-click unsubscribe headers, pixel, tracked links and UTM', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $adapter = maCaptureMail();
    $service = app(MarketingMessageService::class);

    $message = $service->prepare($campaign, $contact)['message'];
    $service->deliver($message);
    $message->refresh();

    expect($adapter->sent)->toHaveCount(1)
        ->and($message->status)->toBe('sent')
        ->and($message->provider_reference)->toBe('ref-1')
        ->and($message->sent_at)->not->toBeNull();

    $email = $adapter->sent[0];
    expect($email->subject)->toBe('Ciao Mario')
        ->and($email->to)->toBe($message->recipient)
        ->and($email->fromEmail)->toBe('info@example.ch')
        ->and($email->headers['List-Unsubscribe'])->toContain('/api/v1/marketing/u/'.$message->token)
        ->and($email->headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($email->html)->toContain('Offerta per Rossi SA')
        ->toContain('Via Test 1, 6900 Lugano')
        ->toContain('/api/v1/marketing/t/o/'.$message->token.'.gif')
        ->toContain('/api/v1/marketing/t/c/'.$message->token)
        ->toContain('LCSl')
        ->not->toContain('href="https://shop.example.ch/offer"')
        ->not->toContain('{{');

    preg_match('#t/c/'.$message->token.'\?([^"]+)"#', $email->html, $m);
    parse_str(html_entity_decode($m[1]), $query);
    $target = app(TrackingService::class)->decodeTarget($query['u'], $query['s']);
    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $utm);

    expect($utm['utm_campaign'])->toBe('spring')
        ->and($utm['utm_medium'])->toBe('email')
        ->and($utm['utm_source'])->toBe('fatturafacile')
        ->and($utm['ff_mt'])->toBe($message->token);
});

test('Italian recipients get the GDPR Art. 130 notice in their language', function (): void {
    [$tenant, , $campaign] = maMailable('IT');
    $contact = maContact($tenant, ['document_language' => 'de', 'first_name' => 'Hans']);
    maConsent($tenant, $contact, ['locale' => 'de']);
    $campaign->forceFill(['template_mjml_json' => ['subject' => ['it' => 'Ciao', 'de' => 'Hallo {{first_name}}'], 'blocks' => [['type' => 'text', 'props' => ['text' => ['it' => 'x', 'de' => 'Guten Tag']]]]]])->save();
    $adapter = maCaptureMail();
    $service = app(MarketingMessageService::class);

    $service->deliver($service->prepare($campaign, $contact)['message']);

    expect($adapter->sent[0]->subject)->toBe('Hallo Hans')
        ->and($adapter->sent[0]->html)->toContain('Guten Tag')->toContain('Art. 130')->toContain('Mit einem Klick abmelden');
});

test('a message to an address that was suppressed meanwhile is not sent', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $adapter = maCaptureMail();
    $service = app(MarketingMessageService::class);
    $message = $service->prepare($campaign, $contact)['message'];

    app(SuppressionService::class)->suppress((string) $tenant->id, (string) $message->recipient, SuppressionReason::Unsubscribe, $contact);
    $service->deliver($message);

    expect($adapter->sent)->toBeEmpty()->and($message->refresh()->status)->toBe('suppressed');
});

test('queueing dispatches the delivery job', function (): void {
    Queue::fake();
    [, $contact, $campaign] = maMailable('CH');
    $service = app(MarketingMessageService::class);

    $service->queue($service->prepare($campaign, $contact)['message']);

    Queue::assertPushed(SendMarketingMessageJob::class);
});

test('a permanent provider failure is a hard bounce and suppresses the address', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    app()->instance(SmtpMailAdapter::class, new class extends SmtpMailAdapter
    {
        public function send(OutboundEmail $email, array $config): array
        {
            throw new Modules\MarketingAutomation\Exceptions\MailDeliveryException('550 5.1.1 user unknown', true);
        }
    });
    $service = app(MarketingMessageService::class);
    $message = $service->prepare($campaign, $contact)['message'];

    $service->deliver($message);

    expect($message->refresh()->status)->toBe('hard_bounced')
        ->and(app(SuppressionService::class)->isSuppressed((string) $tenant->id, (string) $message->recipient))->toBeTrue()
        ->and(app(MetricsService::class)->recompute($campaign)->hard_bounces_count)->toBe(1);
});

test('the open pixel and the signed click redirect record engagement; a forged target is refused', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $message = maMessage($tenant, $campaign, $contact);
    $tracking = app(TrackingService::class);

    $this->get('/api/v1/marketing/t/o/'.$message->token.'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($message->refresh()->first_opened_at)->not->toBeNull()->and($message->first_clicked_at)->toBeNull();

    $this->get($tracking->clickUrl($message->token, 'https://shop.example.ch/offer'))->assertRedirect('https://shop.example.ch/offer');
    expect($message->refresh()->first_clicked_at)->not->toBeNull();

    $this->get('/api/v1/marketing/t/c/'.$message->token.'?u='.rtrim(strtr(base64_encode('https://evil.example'), '+/', '-_'), '=').'&s=forged')->assertNotFound();

    $metric = app(MetricsService::class)->recompute($campaign);
    expect($metric->unique_opens_count)->toBe(1)->and($metric->unique_clicks_count)->toBe(1);
});

test('unsubscribe link: confirmation in the language of the recipient, consent off, suppression and an audit event', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $message = maMessage($tenant, $campaign, $contact);

    $this->withHeader('User-Agent', 'MailClient/1.0')->get('/api/v1/marketing/u/'.$message->token)
        ->assertOk()->assertSee('Iscrizione annullata');

    $consent = MarketingConsent::withoutGlobalScopes()->where('contact_id', $contact)->first();
    $event = ConsentEvent::withoutGlobalScopes()->where('contact_id', $contact)->where('event_type', 'unsubscribe')->first();

    expect($consent->marketing_opt_in)->toBeFalse()->and($consent->unsubscribed_at)->not->toBeNull()
        ->and($event)->not->toBeNull()
        ->and($event->strategy_code)->toBe('ch_lcsl_art3_nlpd')
        ->and($event->user_agent)->toBe('MailClient/1.0')
        ->and($event->source)->toBe('unsubscribe_link')
        ->and(Suppression::withoutGlobalScopes()->where('address', $message->recipient)->value('reason'))->toBe('unsubscribe')
        ->and(app(MetricsService::class)->recompute($campaign)->unsubscribes_count)->toBe(1);

    // Doing it twice is harmless and does not duplicate the audit event.
    $this->get('/api/v1/marketing/u/'.$message->token)->assertOk();
    expect(ConsentEvent::withoutGlobalScopes()->where('contact_id', $contact)->where('event_type', 'unsubscribe')->count())->toBe(1);
});

test('RFC 8058 one-click POST unsubscribes without a page; an unknown token is a 404', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $message = maMessage($tenant, $campaign, $contact);

    $this->post('/api/v1/marketing/u/'.$message->token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertJson(['success' => true]);
    expect(MarketingConsent::withoutGlobalScopes()->where('contact_id', $contact)->value('unsubscribed_at'))->not->toBeNull()
        ->and(ConsentEvent::withoutGlobalScopes()->where('source', 'rfc8058_one_click')->exists())->toBeTrue();

    $this->post('/api/v1/marketing/u/'.str_repeat('z', 40))->assertNotFound();
    $this->get('/api/v1/marketing/u/'.str_repeat('z', 40))->assertNotFound();
});

test('provider webhooks need the shared secret', function (): void {
    config(['marketing_automation.webhook_secret' => 'secret-123']);

    $this->postJson('/api/v1/marketing/webhooks/sendgrid', [])->assertForbidden();
    $this->postJson('/api/v1/marketing/webhooks/sendgrid', [], ['X-Webhook-Secret' => 'wrong'])->assertForbidden();
    $this->postJson('/api/v1/marketing/webhooks/sendgrid', [], ['X-Webhook-Secret' => 'secret-123'])->assertStatus(202);

    config(['marketing_automation.webhook_secret' => '']);
    $this->postJson('/api/v1/marketing/webhooks/sendgrid', [], ['X-Webhook-Secret' => ''])->assertForbidden();
});

test('hard bounce webhooks suppress the address immediately', function (): void {
    config(['marketing_automation.webhook_secret' => 's']);
    [$tenant, $contact, $campaign] = maMailable('CH');
    $message = maMessage($tenant, $campaign, $contact);
    $message->forceFill(['provider_reference' => 'sg-msg-1'])->save();

    $this->postJson('/api/v1/marketing/webhooks/sendgrid', [['event' => 'bounce', 'type' => 'bounce', 'email' => $message->recipient, 'sg_message_id' => 'sg-msg-1.filter0']], ['X-Webhook-Secret' => 's'])
        ->assertStatus(202)->assertJson(['handled' => 1]);

    expect($message->refresh()->status)->toBe('hard_bounced')
        ->and(Suppression::withoutGlobalScopes()->where('address', $message->recipient)->value('reason'))->toBe('hard_bounce')
        ->and(ConsentEvent::withoutGlobalScopes()->where('event_type', 'hard_bounce')->exists())->toBeTrue()
        ->and(app(MetricsService::class)->recompute($campaign)->hard_bounces_count)->toBe(1);
});

test('spam complaints (FBL) suppress the address and switch the consent off', function (): void {
    config(['marketing_automation.webhook_secret' => 's']);
    [$tenant, $contact, $campaign] = maMailable('CH');
    $message = maMessage($tenant, $campaign, $contact);
    $message->forceFill(['provider_reference' => 'mg-1'])->save();

    $this->postJson('/api/v1/marketing/webhooks/mailgun', ['event-data' => ['event' => 'complained', 'recipient' => $message->recipient, 'message' => ['headers' => ['message-id' => 'mg-1']]]], ['X-Webhook-Secret' => 's'])
        ->assertStatus(202);

    expect($message->refresh()->status)->toBe('complained')
        ->and(Suppression::withoutGlobalScopes()->where('address', $message->recipient)->value('reason'))->toBe('spam_complaint')
        ->and(MarketingConsent::withoutGlobalScopes()->where('contact_id', $contact)->value('unsubscribed_at'))->not->toBeNull()
        ->and(app(MetricsService::class)->recompute($campaign)->spam_complaints_count)->toBe(1);
});

test('three soft bounces to the same address turn into a hard bounce', function (): void {
    [$tenant, $contact, $campaign] = maMailable('CH');
    $tracking = app(TrackingService::class);
    $suppressions = app(SuppressionService::class);
    $address = null;

    foreach ([1, 2, 3] as $i) {
        $message = maMessage($tenant, $campaign, $contact);
        $message->forceFill(['provider_reference' => 'soft-'.$i])->save();
        $address = (string) $message->recipient;

        $tracking->registerProviderEvent(['type' => 'soft_bounce', 'address' => $address, 'reference' => 'soft-'.$i], (string) $tenant->id);

        expect($suppressions->isSuppressed((string) $tenant->id, $address))->toBe($i === 3);
    }

    expect(MarketingMessage::withoutGlobalScopes()->where('provider_reference', 'soft-3')->value('status'))->toBe('hard_bounced')
        ->and(MarketingMessage::withoutGlobalScopes()->where('status', 'soft_bounced')->count())->toBe(2);
});

test('provider adapters normalise webhook payloads', function (): void {
    $sendgrid = app(Modules\MarketingAutomation\Services\Delivery\SendGridMailAdapter::class);
    $ses = app(Modules\MarketingAutomation\Services\Delivery\AmazonSesMailAdapter::class);
    $mailgun = app(Modules\MarketingAutomation\Services\Delivery\MailgunMailAdapter::class);

    expect($sendgrid->parseWebhook([['event' => 'spamreport', 'email' => 'a@x.ch'], ['event' => 'processed', 'email' => 'b@x.ch']]))->toBe([['type' => 'complaint', 'address' => 'a@x.ch', 'reference' => null]])
        ->and($mailgun->parseWebhook(['event-data' => ['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'a@x.ch']])[0]['type'])->toBe('soft_bounce')
        ->and($mailgun->parseWebhook(['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'a@x.ch']])[0]['type'])->toBe('hard_bounce')
        ->and($ses->parseWebhook(['Message' => json_encode(['notificationType' => 'Bounce', 'mail' => ['messageId' => 'm1'], 'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'a@x.ch']]]])]))->toBe([['type' => 'hard_bounce', 'address' => 'a@x.ch', 'reference' => 'm1']])
        ->and($ses->parseWebhook(['Message' => json_encode(['notificationType' => 'Complaint', 'mail' => ['messageId' => 'm2'], 'complaint' => ['complainedRecipients' => [['emailAddress' => 'c@x.ch']]]])])[0]['type'])->toBe('complaint');
});
