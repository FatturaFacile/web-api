<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\SDI\Contracts\ImapMailbox;
use Modules\SDI\Services\NativeImapMailbox;
use Modules\ServiceManagement\Jobs\PollSupportMailboxesJob;
use Modules\ServiceManagement\Mail\HelpdeskTicketMail;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Models\SupportMailboxMessage;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Services\Helpdesk\Email\SupportMailboxConnector;
use Modules\ServiceManagement\Services\Helpdesk\Email\SupportMailboxPoller;

uses(RefreshDatabase::class);

/**
 * A raw e-mail with the given headers and plain-text body.
 *
 * @param  array<string, string>  $headers
 */
function helpdeskRawEmail(array $headers, string $body = 'Hello, my printer is broken.'): string
{
    $headers = ['From' => 'Mario Rossi <mario@customer.test>', 'To' => 'support@alfa.test', 'Subject' => 'Printer', 'Message-ID' => '<'.Str::uuid().'@customer.test>', ...$headers];
    $lines = [];

    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    return implode("\r\n", $lines)."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
}

/**
 * Scripts an IMAP server that holds the given messages, keyed by UID, and binds a connector that talks to it.
 *
 * @param  array<int, string>  $messages
 */
function helpdeskScriptedImap(array $messages): void
{
    $script = "* OK ready\r\nA1 OK logged in\r\n* 1 EXISTS\r\nA2 OK selected\r\n* SEARCH ".implode(' ', array_keys($messages))."\r\nA3 OK done\r\n";
    $tag = 4;

    foreach ($messages as $uid => $raw) {
        $script .= "* {$uid} FETCH (UID {$uid} BODY[] {".strlen($raw)."}\r\n{$raw})\r\nA{$tag} OK done\r\n";
        $tag++;
    }

    $pair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($pair[1], $script);

    app()->instance(SupportMailboxConnector::class, new class($pair) extends SupportMailboxConnector
    {
        /**
         * @param  array{0: resource, 1: resource}  $pair
         */
        public function __construct(private array $pair) {}

        public function open(SupportMailbox $mailbox): ImapMailbox
        {
            return NativeImapMailbox::login($this->pair[0], (string) $mailbox->imap_username, (string) $mailbox->imap_password);
        }
    });
}

function helpdeskPollMailbox(SupportMailbox $mailbox): int
{
    return app(SupportMailboxPoller::class)->poll($mailbox->fresh());
}

beforeEach(function (): void {
    Storage::fake('private');
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignTicket = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'Mario@Customer.test']);
    $this->mailbox = SupportMailbox::factory()->create(['tenant_id' => $this->tenant->id, 'email_address' => 'support@alfa.test', 'imap_password' => 'imap-secret', 'default_team_id' => $this->team->id]);
    app(TenantContext::class)->setTenant($this->tenant);
});

test('a new e-mail opens a ticket for the known customer, with the mailbox defaults, and the credentials stay encrypted', function (): void {
    helpdeskScriptedImap([7 => helpdeskRawEmail(['Message-ID' => '<first@customer.test>'])]);

    expect(helpdeskPollMailbox($this->mailbox))->toBe(1);

    $ticket = Ticket::query()->where('tenant_id', $this->tenant->id)->where('source', 'email')->sole();
    $message = TicketMessage::query()->where('ticket_id', $ticket->id)->sole();

    expect($ticket->status)->toBe('new')
        ->and($ticket->subject)->toBe('Printer')
        ->and($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->assigned_team_id)->toBe($this->team->id)
        ->and($ticket->requesterEmail())->toBe('mario@customer.test')
        ->and($message->email_message_id)->toBe('first@customer.test')
        ->and($message->author_type)->toBe('customer')
        ->and($message->channel)->toBe('email')
        ->and($this->mailbox->fresh()->last_uid)->toBe(7)
        ->and(DB::table('esm_support_mailboxes')->where('id', $this->mailbox->id)->value('imap_password'))->not->toBe('imap-secret')
        ->and($this->mailbox->fresh()->imap_password)->toBe('imap-secret');
});

test('replies are threaded by the reference in the subject or by the threading headers', function (): void {
    $ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'pending_customer', 'metadata' => ['contact_email' => 'mario@customer.test']]);
    TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_type' => 'staff', 'email_message_id' => 'outbound-1@helpdesk.test']);

    helpdeskScriptedImap([
        1 => helpdeskRawEmail(['Subject' => "Re: [#{$ticket->ticket_number}] Printer"], 'Here is the photo'),
        2 => helpdeskRawEmail(['Subject' => 'Re: something else', 'In-Reply-To' => '<outbound-1@helpdesk.test>', 'References' => '<outbound-1@helpdesk.test>'], 'And one more thing'),
    ]);

    helpdeskPollMailbox($this->mailbox);

    expect(TicketMessage::query()->where('ticket_id', $ticket->id)->where('channel', 'email')->pluck('body')->all())->toBe(['Here is the photo', 'And one more thing'])
        ->and($ticket->fresh()->status)->toBe('open')
        ->and(Ticket::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(SupportMailboxMessage::query()->where('mailbox_id', $this->mailbox->id)->pluck('outcome')->all())->toBe(['threaded', 'threaded']);
});

test('auto-replies, bounces, mail loops and duplicates never become tickets', function (): void {
    config(['helpdesk.inbound_max_messages_per_sender_per_hour' => 2]);

    helpdeskScriptedImap([
        1 => helpdeskRawEmail(['Auto-Submitted' => 'auto-replied', 'Subject' => 'Out of office']),
        2 => helpdeskRawEmail(['From' => 'MAILER-DAEMON@mx.test', 'Subject' => 'Undeliverable']),
        3 => helpdeskRawEmail(['From' => 'support@alfa.test']),
        4 => helpdeskRawEmail(['X-FF-Helpdesk' => 'TCK-20270101-AAAAAA']),
        5 => helpdeskRawEmail(['Message-ID' => '<same@customer.test>', 'From' => 'anna@customer.test']),
        6 => helpdeskRawEmail(['Message-ID' => '<same@customer.test>', 'From' => 'anna@customer.test']),
        7 => helpdeskRawEmail(['From' => 'loop@robot.test']),
        8 => helpdeskRawEmail(['From' => 'loop@robot.test']),
        9 => helpdeskRawEmail(['From' => 'loop@robot.test']),
    ]);

    helpdeskPollMailbox($this->mailbox);

    expect(SupportMailboxMessage::query()->where('mailbox_id', $this->mailbox->id)->orderBy('uid')->pluck('outcome')->all())
        ->toBe(['ignored_auto_reply', 'ignored_auto_reply', 'ignored_loop', 'ignored_loop', 'created', 'duplicate', 'created', 'created', 'ignored_loop'])
        ->and(Ticket::query()->where('tenant_id', $this->tenant->id)->count())->toBe(3);
});

test('a reference to a ticket of another company opens a new ticket in the mailbox company', function (): void {
    helpdeskScriptedImap([1 => helpdeskRawEmail(['Subject' => "Re: [#{$this->foreignTicket->ticket_number}] Hello"])]);

    helpdeskPollMailbox($this->mailbox);

    expect(TicketMessage::query()->withoutGlobalScopes()->where('ticket_id', $this->foreignTicket->id)->count())->toBe(0)
        ->and(Ticket::query()->where('tenant_id', $this->tenant->id)->where('subject', 'Re: Hello')->count())->toBe(1);
});

test('a public reply goes out by e-mail with threading headers, an internal note does not', function (): void {
    helpdeskScriptedImap([1 => helpdeskRawEmail(['Message-ID' => '<question@customer.test>'])]);
    helpdeskPollMailbox($this->mailbox);
    $ticket = Ticket::query()->where('tenant_id', $this->tenant->id)->sole();

    // The new ticket was acknowledged automatically, threaded on the customer's message.
    Mail::assertSent(HelpdeskTicketMail::class, fn (HelpdeskTicketMail $mail): bool => $mail->isAutomatic
        && $mail->hasTo('mario@customer.test')
        && $mail->headers()->text['Auto-Submitted'] === 'auto-generated'
        && $mail->headers()->text['In-Reply-To'] === '<question@customer.test>');
    Mail::fake();

    $agent = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $agent->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($agent);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'Internal only', 'visibility' => 'internal'])->assertCreated();
    Mail::assertNothingSent();

    $reply = $this->postJson("/api/v1/esm/tickets/{$ticket->id}/messages", ['body' => 'Please restart it'])->assertCreated()->json('data');

    Mail::assertSent(HelpdeskTicketMail::class, function (HelpdeskTicketMail $mail) use ($ticket): bool {
        $headers = $mail->headers();

        return $mail->hasTo('mario@customer.test')
            && $mail->hasFrom('support@alfa.test')
            && $mail->mailSubject === "Re: [#{$ticket->ticket_number}] Printer"
            && $headers->text['In-Reply-To'] === '<question@customer.test>'
            && $headers->references === ['question@customer.test']
            && ! isset($headers->text['Auto-Submitted'])
            && isset($headers->text['X-FF-Helpdesk']);
    });

    expect(TicketMessage::query()->findOrFail($reply['id'])->email_message_id)->toEndWith('@'.config('helpdesk.message_id_domain'));
});

test('the scheduled poll runs per tenant on its active mailboxes only', function (): void {
    SupportMailbox::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => false, 'email_address' => 'old@alfa.test']);
    helpdeskScriptedImap([3 => helpdeskRawEmail([])]);

    (new PollSupportMailboxesJob($this->tenant->id))->handle(app(SupportMailboxPoller::class));

    expect(Ticket::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(collect(app(Schedule::class)->events())->pluck('description')->all())->toContain('esm:support-mailboxes');
});

test('only service managers manage support mailboxes and credentials are never returned', function (): void {
    $manager = User::create(['name' => 'Manager', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $manager->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'service_manager']);
    $technician = User::create(['name' => 'Tech', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $technician->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'technician']);
    $foreignMailbox = app(TenantContext::class)->runWithTenant($this->other, fn (): SupportMailbox => SupportMailbox::factory()->create(['tenant_id' => $this->other->id]));
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    Sanctum::actingAs($technician);
    $this->postJson('/api/v1/esm/helpdesk/mailboxes', ['name' => 'Sales', 'email_address' => 'sales@alfa.test', 'imap_host' => 'imap.alfa.test', 'imap_username' => 'u', 'imap_password' => 'p'])->assertForbidden();

    Sanctum::actingAs($manager);
    $created = $this->postJson('/api/v1/esm/helpdesk/mailboxes', ['name' => 'Sales', 'email_address' => 'sales@alfa.test', 'imap_host' => 'imap.alfa.test', 'imap_username' => 'u', 'imap_password' => 'p'])
        ->assertCreated()->assertJsonPath('data.has_credentials', true)->json('data');

    expect($created)->not->toHaveKeys(['imap_password', 'imap_username']);
    $this->getJson('/api/v1/esm/helpdesk/mailboxes')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['imap_password' => 'imap-secret']);
    $this->patchJson("/api/v1/esm/helpdesk/mailboxes/{$foreignMailbox->id}", ['name' => 'X'])->assertNotFound();
    $this->postJson('/api/v1/esm/helpdesk/mailboxes', ['name' => 'Dup', 'email_address' => 'sales@alfa.test', 'imap_host' => 'h', 'imap_username' => 'u', 'imap_password' => 'p'])->assertUnprocessable();
});
