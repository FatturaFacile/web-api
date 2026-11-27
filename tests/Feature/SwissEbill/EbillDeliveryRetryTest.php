<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\SwissEbill\Application\Commands\TransmitInvoiceToEbillHandler;
use Modules\SwissEbill\Application\Dto\GatewayReceipt;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Services\EbillDeliveryService;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Infrastructure\Gateways\HttpEbillGateway;
use Modules\SwissEbill\Infrastructure\Gateways\SandboxEbillGateway;
use Modules\SwissEbill\Infrastructure\Jobs\AutoTransmitInvoiceJob;
use Modules\SwissEbill\Infrastructure\Jobs\TransmitEbillJob;
use Modules\SwissEbill\Listeners\AutoTransmitOnInvoicePosted;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillTransmission;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

/**
 * The sandbox with a script of failures: each submission takes the next entry (a throwable fails, null succeeds).
 */
final class EbillScriptedGateway extends SandboxEbillGateway
{
    /** @var list<Throwable|null> */
    public array $script = [];

    public int $submissions = 0;

    public function submit(EbillSetting $setting, GatewaySubmission $submission): GatewayReceipt
    {
        $this->submissions++;
        $next = array_shift($this->script);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return parent::submit($setting, $submission);
    }
}

beforeEach(function (): void {
    ebSetUp($this);
    Queue::fake();
    $this->gateway = new EbillScriptedGateway;
    $this->app->instance(EbillGatewayFactory::class, new EbillGatewayFactory($this->gateway, app(HttpEbillGateway::class)));
    $this->invoice = ebInvoice(ebCustomer());
});

function ebQueued(object $test): EbillTransmission
{
    $id = $test->postJson('/api/v1/swiss-ebill/invoices/'.$test->invoice->id.'/send')->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');

    return EbillTransmission::query()->findOrFail($id);
}

test('sending queues one delivery job and nothing is sent yet', function (): void {
    $transmission = ebQueued($this);

    Queue::assertPushed(TransmitEbillJob::class, 1);
    Queue::assertPushed(TransmitEbillJob::class, fn (TransmitEbillJob $job): bool => $job->transmissionId === $transmission->id && $job->tenantId === $this->tenant->id);

    expect($this->gateway->submissions)->toBe(0)->and($transmission->attempt_count)->toBe(0)->and($this->invoice->refresh()->ebill_status)->toBe('queued');
});

test('a transient gateway failure schedules a retry with backoff and the next attempt succeeds', function (): void {
    $transmission = ebQueued($this);
    $this->gateway->script = [new EbillGatewayException('Service unavailable', 503)];

    expect(fn () => app(EbillDeliveryService::class)->deliver($transmission->id))->toThrow(EbillGatewayException::class);

    $transmission->refresh();

    expect($transmission->status->value)->toBe('queued')
        ->and($transmission->attempt_count)->toBe(1)
        ->and($transmission->next_retry_at?->toDateTimeString())->toBe(now()->addSeconds(30)->toDateTimeString())
        ->and($transmission->error_details)->toContain('Service unavailable')
        ->and($transmission->events()->where('event_type', 'retry_scheduled')->first()->details)->toMatchArray(['attempt' => 1, 'delay_seconds' => 30, 'http_status' => 503]);

    app(EbillDeliveryService::class)->deliver($transmission->id);
    $transmission->refresh();

    expect($transmission->status->value)->toBe('transmitted')
        ->and($transmission->attempt_count)->toBe(2)
        ->and($transmission->next_retry_at)->toBeNull()
        ->and($transmission->error_code)->toBeNull()
        ->and($transmission->events()->pluck('event_type')->all())->toBe(['prepared', 'queued', 'retry_scheduled', 'transmitted']);
});

test('the backoff grows with every transient failure', function (): void {
    $transmission = ebQueued($this);
    $this->gateway->script = [new EbillGatewayException('429', 429), new EbillGatewayException('500', 500), new EbillGatewayException('timeout', null, 'network_error', true)];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            app(EbillDeliveryService::class)->deliver($transmission->id);
        } catch (EbillGatewayException) {
            // expected: the queue retries
        }
    }

    $delays = $transmission->events()->where('event_type', 'retry_scheduled')->get()->map(fn ($event): int => $event->details['delay_seconds'])->all();

    expect($delays)->toBe([30, 120, 600])->and($transmission->refresh()->attempt_count)->toBe(3);
});

test('a permanent refusal fails the transmission without retrying', function (): void {
    $transmission = ebQueued($this);
    $this->gateway->script = [new EbillGatewayException('Schema rejected: line 3', 422, 'schema_rejected')];

    app(EbillDeliveryService::class)->deliver($transmission->id);
    $transmission->refresh();

    expect($transmission->status->value)->toBe('failed_technical')
        ->and($transmission->error_code)->toBe('schema_rejected')
        ->and($transmission->error_details)->toContain('Schema rejected')
        ->and($this->invoice->refresh()->ebill_status)->toBe('failed_technical')
        ->and($transmission->events()->where('event_type', 'failed_technical')->first()->details['http_status'])->toBe(422);
});

test('a failed transmission is retried by hand with the same payload and a reset counter', function (): void {
    $transmission = ebQueued($this);
    $this->gateway->script = [new EbillGatewayException('Bad request', 400, 'bad_request')];
    app(EbillDeliveryService::class)->deliver($transmission->id);
    $payloadHash = $transmission->refresh()->xml_sha256;

    $this->getJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id)->assertOk()->assertJsonPath('data.can_retry', true);
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/retry')->assertStatus(202)->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(TransmitEbillJob::class, 2);

    app(EbillDeliveryService::class)->deliver($transmission->id);
    $transmission->refresh();

    expect($transmission->status->value)->toBe('transmitted')
        ->and($transmission->attempt_count)->toBe(1)
        ->and($transmission->xml_sha256)->toBe($payloadHash)
        ->and($transmission->error_code)->toBeNull()
        ->and($transmission->events()->pluck('event_type')->all())->toContain('failed_technical', 'retry_requested', 'transmitted');
});

test('only a technical failure can be retried', function (): void {
    $transmission = ebQueued($this);

    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/retry')->assertStatus(409)->assertJsonPath('code', 'cannot_retry');
});

test('a failed transmission cannot be retried while another one of the invoice is alive', function (): void {
    $transmission = ebQueued($this);
    $this->gateway->script = [new EbillGatewayException('Bad request', 400, 'bad_request')];
    app(EbillDeliveryService::class)->deliver($transmission->id);

    $this->postJson('/api/v1/swiss-ebill/invoices/'.$this->invoice->id.'/send')->assertStatus(202);
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/retry')->assertStatus(409)->assertJsonPath('code', 'already_transmitted');
});

test('when the attempts run out the transmission fails with retries_exhausted', function (): void {
    $transmission = ebQueued($this);
    $job = new TransmitEbillJob($transmission->id, $this->tenant->id);

    $job->failed(new EbillGatewayException('Service unavailable', 503));
    $transmission->refresh();

    expect($transmission->status->value)->toBe('failed_technical')->and($transmission->error_code)->toBe('retries_exhausted');
});

test('the job retries as often as the backoff list says', function (): void {
    $job = new TransmitEbillJob('id', 'tenant');

    expect($job->tries)->toBe(count(config('swiss_ebill.retry_backoff_seconds')) + 1)->and($job->backoff())->toBe(config('swiss_ebill.retry_backoff_seconds'));
});

test('running the job delivers the transmission inside the company context', function (): void {
    $transmission = ebQueued($this);

    (new TransmitEbillJob($transmission->id, $this->tenant->id))->handle(app(TenantContext::class), app(EbillDeliveryService::class));

    expect($transmission->refresh()->status->value)->toBe('transmitted');
});

test('the same job twice sends once', function (): void {
    $transmission = ebQueued($this);

    app(EbillDeliveryService::class)->deliver($transmission->id);
    app(EbillDeliveryService::class)->deliver($transmission->id);

    expect($this->gateway->submissions)->toBe(1)->and($transmission->refresh()->attempt_count)->toBe(1);
});

test('a lost delivery is re-queued by the polling fallback', function (): void {
    ebQueued($this);
    ebAdvance($this, 20);

    $this->artisan('ebill:poll-pending-statuses')->assertSuccessful();

    Queue::assertPushed(TransmitEbillJob::class, 2);
});

test('the transient errors are told from the permanent ones', function (): void {
    expect((new EbillGatewayException('x', 429))->isTransient())->toBeTrue()
        ->and((new EbillGatewayException('x', 500))->isTransient())->toBeTrue()
        ->and((new EbillGatewayException('x', 503))->isTransient())->toBeTrue()
        ->and((new EbillGatewayException('x', null, null, true))->isTransient())->toBeTrue()
        ->and((new EbillGatewayException('x', 400))->isTransient())->toBeFalse()
        ->and((new EbillGatewayException('x', 401))->isTransient())->toBeFalse()
        ->and((new EbillGatewayException('x', 422))->isTransient())->toBeFalse()
        ->and((new EbillGatewayException('x'))->isTransient())->toBeFalse();
});

test('the sandbox is refused in production', function (): void {
    $transmission = ebQueued($this);
    $this->app->instance(EbillGatewayFactory::class, new EbillGatewayFactory(new SandboxEbillGateway, app(HttpEbillGateway::class)));
    $this->app['env'] = 'production';

    app(EbillDeliveryService::class)->deliver($transmission->id);

    expect($transmission->refresh()->status->value)->toBe('failed_technical')->and($transmission->error_code)->toBe('sandbox_not_allowed');
});

test('an issued invoice goes out by itself when auto-send is on and the customer is enrolled', function (): void {
    ebSettings(['auto_send_on_invoice_issue' => true]);
    $enrolled = ebInvoice(ebCustomer());
    $notEnrolled = ebInvoice(ebCustomer(['ebill_status' => 'not_enrolled', 'ebill_recipient_id' => null]));
    $optedOut = ebInvoice(ebCustomer(['ebill_opt_in_preferred' => false]));

    foreach ([$enrolled, $notEnrolled, $optedOut] as $invoice) {
        (new AutoTransmitInvoiceJob($invoice->id, $this->tenant->id))->handle(app(TenantContext::class), app(EbillSettingRepositoryInterface::class), app(TransmitInvoiceToEbillHandler::class));
    }

    expect(EbillTransmission::query()->where('invoice_id', $enrolled->id)->count())->toBe(1)
        ->and(EbillTransmission::query()->where('invoice_id', $notEnrolled->id)->exists())->toBeFalse()
        ->and(EbillTransmission::query()->where('invoice_id', $optedOut->id)->exists())->toBeFalse();
});

test('auto-send does nothing when it is off, and never blocks on an incomplete invoice', function (): void {
    $invoice = ebInvoice(ebCustomer());
    $run = fn () => (new AutoTransmitInvoiceJob($invoice->id, $this->tenant->id))->handle(app(TenantContext::class), app(EbillSettingRepositoryInterface::class), app(TransmitInvoiceToEbillHandler::class));

    $run();
    expect(EbillTransmission::query()->count())->toBe(0);

    ebSettings(['auto_send_on_invoice_issue' => true]);
    $this->tenant->forceFill(['uid_number' => null])->save();
    $run();

    expect(EbillTransmission::query()->count())->toBe(0);
});

test('posting an invoice queues the auto-send job', function (): void {
    expect(Event::hasListeners(InvoicePostedEvent::class))->toBeTrue();

    $event = new InvoicePostedEvent($this->invoice->id, $this->invoice->invoice_number, $this->invoice->contact_id, '2026-03-01', '2026-03-31', 'CHF', 1.0, 100.0, 8.1, 108.1, [], [], null, $this->tenant->id, $this->tenant->id);
    app(AutoTransmitOnInvoicePosted::class)->handle($event);

    Queue::assertPushed(AutoTransmitInvoiceJob::class, fn (AutoTransmitInvoiceJob $job): bool => $job->invoiceId === $this->invoice->id && $job->tenantId === $this->tenant->id);
});
