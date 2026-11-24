<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\KnowledgeCategory;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketKnowledgeLink;

uses(RefreshDatabase::class);

function helpdeskKnowledgeMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignArticle = KnowledgeArticle::factory()->create(['tenant_id' => $this->other->id, 'title' => 'Reset the router', 'content' => 'router reset steps', 'visibility' => 'public', 'is_published' => true]);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open']);
    $this->token = app(PortalTokenService::class)->createToken($this->customer)['token'];
    $this->manager = helpdeskKnowledgeMember($this->tenant, 'service_manager');
    Sanctum::actingAs($this->manager);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('an article keeps a version for every change of its text and an earlier version can be restored', function (): void {
    $article = $this->postJson('/api/v1/esm/kb/articles', ['title' => 'Reset the router', 'content' => 'Unplug it for ten seconds.'])
        ->assertCreated()->assertJsonPath('data.current_version', 1)->assertJsonPath('data.visibility', 'internal')->json('data');

    $this->patchJson("/api/v1/esm/kb/articles/{$article['id']}", ['content' => 'Hold the reset button for ten seconds.', 'change_note' => 'New firmware'])->assertOk()->assertJsonPath('data.current_version', 2);
    $this->patchJson("/api/v1/esm/kb/articles/{$article['id']}", ['tags' => ['network']])->assertOk()->assertJsonPath('data.current_version', 2);

    $this->getJson("/api/v1/esm/kb/articles/{$article['id']}/versions")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.change_note', 'New firmware');

    $this->postJson("/api/v1/esm/kb/articles/{$article['id']}/versions/1/restore")->assertOk()
        ->assertJsonPath('data.current_version', 3)
        ->assertJsonPath('data.content', 'Unplug it for ten seconds.');
});

test('search finds articles of the tenant only, by full text', function (): void {
    KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Printer offline', 'summary' => null, 'content' => 'Check the network cable of the printer.']);
    KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Expense report', 'summary' => null, 'content' => 'How to file expenses.']);

    $titles = collect($this->getJson('/api/v1/esm/kb/articles?q=printer')->assertOk()->json('data'))->pluck('title')->all();
    expect($titles)->toBe(['Printer offline']);

    $this->getJson('/api/v1/esm/kb/articles?q=router')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/esm/kb/articles/{$this->foreignArticle->id}")->assertNotFound();
});

test('articles link to tickets, also through the resolution, and foreign articles are refused', function (): void {
    $article = KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id]);
    $other = KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/knowledge-links", ['article_id' => $article->id])->assertCreated();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/knowledge-links", ['article_id' => $this->foreignArticle->id])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/resolve", ['resolution_notes' => 'Done', 'knowledge_article_id' => $other->id])->assertOk();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/resolve", ['knowledge_article_id' => $this->foreignArticle->id])->assertUnprocessable();

    $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/knowledge-links")->assertOk()->assertJsonCount(2, 'data');
    expect($this->ticket->fresh()->knowledge_article_id)->toBe($other->id);

    $this->deleteJson("/api/v1/esm/tickets/{$this->ticket->id}/knowledge-links/{$article->id}")->assertNoContent();
    expect(TicketKnowledgeLink::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1);
});

test('the portal reads published public articles of its tenant only', function (): void {
    $public = KnowledgeCategory::factory()->create(['tenant_id' => $this->tenant->id, 'visibility' => 'public']);
    $internalCategory = KnowledgeCategory::factory()->create(['tenant_id' => $this->tenant->id, 'visibility' => 'internal']);
    $visible = KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Router guide', 'content' => 'router steps', 'visibility' => 'public', 'is_published' => true, 'knowledge_category_id' => $public->id, 'views_count' => 0]);
    KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Router internals', 'content' => 'router admin password rotation', 'visibility' => 'internal', 'is_published' => true]);
    KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Router draft', 'content' => 'router draft', 'visibility' => 'public', 'is_published' => false]);
    $hidden = KnowledgeArticle::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Router in internal category', 'content' => 'router', 'visibility' => 'public', 'is_published' => true, 'knowledge_category_id' => $internalCategory->id]);

    $list = $this->getJson("/api/v1/portal/kb/{$this->token}/articles?q=router")->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$visible->id])
        ->and($list[0])->not->toHaveKey('content');

    $this->getJson("/api/v1/portal/kb/{$this->token}/articles/{$visible->id}")->assertOk()->assertJsonPath('data.content', 'router steps');
    $this->getJson("/api/v1/portal/kb/{$this->token}/articles/{$hidden->id}")->assertNotFound();
    $this->getJson("/api/v1/portal/kb/{$this->token}/articles/{$this->foreignArticle->id}")->assertNotFound();
    $this->getJson("/api/v1/portal/kb/{$this->token}/categories")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/portal/kb/not-a-token/articles')->assertNotFound();

    expect($visible->fresh()->views_count)->toBe(1);
});

test('agents write internal articles but only a service manager publishes for customers', function (): void {
    Sanctum::actingAs(helpdeskKnowledgeMember($this->tenant, 'technician'));

    $draft = $this->postJson('/api/v1/esm/kb/articles', ['title' => 'Draft', 'content' => 'x', 'visibility' => 'public'])->assertCreated()->json('data');
    $this->postJson('/api/v1/esm/kb/articles', ['title' => 'Live', 'content' => 'x', 'visibility' => 'public', 'is_published' => true])->assertForbidden();
    $this->patchJson("/api/v1/esm/kb/articles/{$draft['id']}", ['is_published' => true])->assertForbidden();
    $this->postJson('/api/v1/esm/kb/categories', ['name' => 'FAQ'])->assertForbidden();

    Sanctum::actingAs($this->manager);
    $this->patchJson("/api/v1/esm/kb/articles/{$draft['id']}", ['is_published' => true])->assertOk()->assertJsonPath('data.is_published', true);
});
