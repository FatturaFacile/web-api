<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Collaboration\Models\CollabComment;
use Modules\Collaboration\Models\CollabTag;
use Modules\Collaboration\Notifications\MentionedInCommentNotification;
use Modules\Collaboration\Services\CollaborationRegistry;
use Modules\Collaboration\Services\RecordType;
use Modules\HumanResources\Models\Employee;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = cbTenant('Studio');
    $this->owner = cbMember($this->tenant, 'owner');
    cbAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function cbTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function cbMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@collab-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function cbAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function cbInTenant(Tenant $tenant, Closure $callback): mixed
{
    $context = app(TenantContext::class);
    $context->setTenant($tenant);

    try {
        return $callback();
    } finally {
        $context->setTenant(test()->tenant);
    }
}

function cbContact(?Tenant $tenant = null, string $name = 'Acme'): Contact
{
    $tenant ??= test()->tenant;

    return cbInTenant($tenant, fn (): Contact => Contact::factory()->create(['tenant_id' => $tenant->id, 'company_name' => $name.' '.Str::random(4), 'type' => 'customer']));
}

const CB_BASE = '/api/v1/collaboration';

test('the search finds records of several types, whatever the case, inside the company only', function (): void {
    $acme = cbContact(name: 'Zorbaflex');
    $invoice = SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $acme->id, 'invoice_number' => 'ZORBA-001']);
    cbContact(cbTenant('Other'), 'Zorbaflex Rival');

    $response = $this->getJson(CB_BASE.'/search?q=zorba')->assertOk();

    $found = collect($response->json('data'));
    expect($found->pluck('type')->sort()->values()->all())->toBe(['contact', 'invoice'])
        ->and($found->firstWhere('type', 'contact')['id'])->toBe($acme->id)
        ->and($found->firstWhere('type', 'invoice')['id'])->toBe($invoice->id)
        ->and($found->firstWhere('type', 'invoice')['title'])->toBe('ZORBA-001');

    $this->getJson(CB_BASE.'/search?q=zorba&types[]=invoice')->assertOk()->assertJsonCount(1, 'data');
});

test('the search escapes like wildcards and requires two characters', function (): void {
    cbContact(name: 'Plain');

    $this->getJson(CB_BASE.'/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(CB_BASE.'/search?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson(CB_BASE.'/search?q=plain&types[]=nope')->assertUnprocessable()->assertJsonValidationErrors('types.0');
});

test('the search skips the types whose module the member may not read', function (): void {
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Quentin', 'last_name' => 'Zzyzx', 'full_name' => 'Quentin Zzyzx']);

    $this->getJson(CB_BASE.'/search?q=zzyzx')->assertOk()->assertJsonCount(1, 'data');

    $sales = cbMember($this->tenant, 'sales');
    cbAct($sales, $this->tenant);

    $this->getJson(CB_BASE.'/search?q=zzyzx')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(CB_BASE.'/record-types')->assertOk()->assertJsonMissing(['key' => 'employee']);
});

test('modules can register their own searchable record type', function (): void {
    app(CollaborationRegistry::class)->register(new RecordType(
        key: 'person',
        modelClass: Contact::class,
        module: 'crm',
        labelKey: 'collaboration.types.contact',
        searchColumns: ['last_name'],
        title: static fn ($record): string => (string) $record->last_name,
        path: '/people/{id}',
    ));
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => null, 'first_name' => 'Ada', 'last_name' => 'Lovelacefoo']);

    $hits = collect($this->getJson(CB_BASE.'/search?q=lovelacefoo&types[]=person')->assertOk()->json('data'));

    expect($hits->pluck('path')->all())->toBe(['/people/'.$contact->id]);
});

test('a member comments with mentions: mentioned members are notified, the author is not', function (): void {
    Notification::fake();
    $contact = cbContact();
    $colleague = cbMember($this->tenant, 'accountant');

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/comments", ['body' => 'Please check', 'mentions' => [$colleague->id, $this->owner->id]])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Please check')
        ->assertJsonPath('data.mentions', [$colleague->id])
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.author.id', $this->owner->id);

    Notification::assertSentTo($colleague, MentionedInCommentNotification::class);
    Notification::assertNotSentTo($this->owner, MentionedInCommentNotification::class);

    $this->getJson(CB_BASE."/records/contact/{$contact->id}/comments")->assertOk()->assertJsonCount(1, 'data');
});

test('the mention notification is stored for the mentioned member', function (): void {
    $contact = cbContact();
    $colleague = cbMember($this->tenant, 'accountant');

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/comments", ['body' => 'Hello', 'mentions' => [$colleague->id]])->assertCreated();

    $stored = $colleague->notifications()->first();
    expect($stored)->not->toBeNull()
        ->and($stored->data['kind'])->toBe('collaboration_mention')
        ->and($stored->data['record_id'])->toBe($contact->id);
});

test('a comment cannot mention someone outside the company or point at an unknown record', function (): void {
    $contact = cbContact();
    $outsider = cbMember(cbTenant('Other'), 'owner');

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/comments", ['body' => 'Hi', 'mentions' => [$outsider->id]])->assertUnprocessable()->assertJsonValidationErrors('mentions.0');
    $this->postJson(CB_BASE.'/records/contact/'.Str::uuid().'/comments', ['body' => 'Hi'])->assertNotFound();
    $this->postJson(CB_BASE."/records/secret_table/{$contact->id}/comments", ['body' => 'Hi'])->assertNotFound();
    $this->postJson(CB_BASE."/records/contact/{$contact->id}/comments", ['body' => ''])->assertUnprocessable();

    $foreign = cbContact(cbTenant('Third'));
    $this->postJson(CB_BASE."/records/contact/{$foreign->id}/comments", ['body' => 'Hi'])->assertNotFound();
});

test('a member without read access to the module of a record cannot comment on it', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    cbAct(cbMember($this->tenant, 'sales'), $this->tenant);

    $this->postJson(CB_BASE."/records/employee/{$employee->id}/comments", ['body' => 'Hi'])->assertForbidden();
    $this->getJson(CB_BASE."/records/employee/{$employee->id}/activity")->assertForbidden();
});

test('only the author edits a comment, and owners or the author delete it', function (): void {
    $contact = cbContact();
    $author = cbMember($this->tenant, 'sales');
    $other = cbMember($this->tenant, 'member');
    $comment = CollabComment::factory()->create(['tenant_id' => $this->tenant->id, 'commentable_type' => 'contact', 'commentable_id' => $contact->id, 'user_id' => $author->id]);

    cbAct($other, $this->tenant);
    $this->putJson(CB_BASE."/comments/{$comment->id}", ['body' => 'Hijack'])->assertForbidden();
    $this->deleteJson(CB_BASE."/comments/{$comment->id}")->assertForbidden();

    cbAct($author, $this->tenant);
    $this->putJson(CB_BASE."/comments/{$comment->id}", ['body' => 'Edited'])->assertOk()->assertJsonPath('data.body', 'Edited');
    expect($comment->fresh()->edited_at)->not->toBeNull();

    cbAct($this->owner, $this->tenant);
    $this->putJson(CB_BASE."/comments/{$comment->id}", ['body' => 'Owner edit'])->assertForbidden();
    $this->deleteJson(CB_BASE."/comments/{$comment->id}")->assertNoContent();
    expect(CollabComment::query()->count())->toBe(0);

    $mine = CollabComment::factory()->create(['tenant_id' => $this->tenant->id, 'commentable_type' => 'contact', 'commentable_id' => $contact->id, 'user_id' => $other->id]);
    cbAct($other, $this->tenant);
    $this->deleteJson(CB_BASE."/comments/{$mine->id}")->assertNoContent();
});

test('an edit notifies only the members it newly mentions', function (): void {
    $contact = cbContact();
    $first = cbMember($this->tenant, 'accountant');
    $second = cbMember($this->tenant, 'sales');
    $commentId = $this->postJson(CB_BASE."/records/contact/{$contact->id}/comments", ['body' => 'One', 'mentions' => [$first->id]])->json('data.id');

    Notification::fake();
    $this->putJson(CB_BASE."/comments/{$commentId}", ['body' => 'Two', 'mentions' => [$first->id, $second->id]])->assertOk();

    Notification::assertSentTo($second, MentionedInCommentNotification::class);
    Notification::assertNotSentTo($first, MentionedInCommentNotification::class);
});

test('comments of another company are invisible', function (): void {
    $other = cbTenant('Other');
    $foreign = cbInTenant($other, fn (): CollabComment => CollabComment::factory()->create(['tenant_id' => $other->id, 'commentable_type' => 'contact', 'commentable_id' => (string) Str::uuid(), 'user_id' => cbMember($other, 'owner')->id]));

    $this->putJson(CB_BASE."/comments/{$foreign->id}", ['body' => 'x'])->assertNotFound();
    $this->deleteJson(CB_BASE."/comments/{$foreign->id}")->assertNotFound();
});

test('tags attach by name or id, once, and list the tagged records', function (): void {
    $contact = cbContact();
    $invoice = SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id]);

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/tags", ['name' => 'VIP'])->assertOk()->assertJsonPath('data.0.name', 'VIP');
    $tag = CollabTag::query()->firstOrFail();

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/tags", ['name' => 'vip'])->assertOk()->assertJsonCount(1, 'data');
    $this->postJson(CB_BASE."/records/invoice/{$invoice->id}/tags", ['tag_id' => $tag->id])->assertOk();

    expect(CollabTag::query()->count())->toBe(1);

    $this->getJson(CB_BASE."/records/contact/{$contact->id}/tags")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(CB_BASE.'/tags')->assertOk()->assertJsonPath('data.0.usage_count', 2);

    $records = collect($this->getJson(CB_BASE."/tags/{$tag->id}/records")->assertOk()->json('data'));
    expect($records->pluck('type')->sort()->values()->all())->toBe(['contact', 'invoice']);
    $this->getJson(CB_BASE."/tags/{$tag->id}/records?type=invoice")->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson(CB_BASE."/records/contact/{$contact->id}/tags/{$tag->id}")->assertNoContent();
    $this->getJson(CB_BASE."/records/contact/{$contact->id}/tags")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(CB_BASE."/records/contact/{$contact->id}/tags", ['tag_id' => $tag->id])->assertOk()->assertJsonCount(1, 'data');
});

test('tags are managed with unique names and deleting one removes its links', function (): void {
    $contact = cbContact();

    $this->postJson(CB_BASE.'/tags', ['name' => 'Late payer', 'color' => '#ff0000'])->assertCreated()->assertJsonPath('data.color', '#ff0000');
    $this->postJson(CB_BASE.'/tags', ['name' => 'Late payer'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson(CB_BASE.'/tags', ['name' => 'Bad colour', 'color' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('color');

    $tag = CollabTag::query()->firstOrFail();
    $this->putJson(CB_BASE."/tags/{$tag->id}", ['name' => 'Late payer'])->assertOk();
    $this->putJson(CB_BASE."/tags/{$tag->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/tags", ['tag_id' => $tag->id])->assertOk();
    $this->deleteJson(CB_BASE."/tags/{$tag->id}")->assertNoContent();

    $this->getJson(CB_BASE."/records/contact/{$contact->id}/tags")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(CB_BASE.'/tags', ['name' => 'Late payer'])->assertCreated();
});

test('tags of another company cannot be attached or read', function (): void {
    $contact = cbContact();
    $other = cbTenant('Other');
    $foreign = cbInTenant($other, fn (): CollabTag => CollabTag::factory()->create(['tenant_id' => $other->id]));

    $this->postJson(CB_BASE."/records/contact/{$contact->id}/tags", ['tag_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('tag_id');
    $this->getJson(CB_BASE."/tags/{$foreign->id}/records")->assertNotFound();
    $this->getJson(CB_BASE.'/tags')->assertOk()->assertJsonCount(0, 'data');
});

test('the activity timeline lists the audit ledger entries of the record, newest first', function (): void {
    $contact = cbContact();
    $invoice = SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'invoice_number' => 'ACT-1']);
    $invoice->update(['invoice_number' => 'ACT-2']);
    SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id]);

    $response = $this->getJson(CB_BASE."/records/invoice/{$invoice->id}/activity")->assertOk();

    $actions = collect($response->json('data'))->pluck('action')->all();
    expect($actions)->toBe(['UPDATED', 'CREATED'])
        ->and($response->json('data.0.changed_fields'))->toContain('invoice_number');
});
