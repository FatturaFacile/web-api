<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CopilotRegulatoryKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Copilot\Commands\IndexTenantKnowledgeRagCommand;
use Modules\Copilot\Models\CopilotKnowledgeChunk;
use Modules\Copilot\Services\KnowledgeRetriever;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(TenantContext::class)->clear();
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->seed(CopilotRegulatoryKnowledgeSeeder::class);
});

afterEach(fn () => app(TenantContext::class)->clear());

function copilotGlobalChunks(): int
{
    return app(TenantContext::class)->runAsGlobal(static fn (): int => CopilotKnowledgeChunk::query()->whereNull('tenant_id')->count());
}

test('the regulatory seeder is idempotent and covers the four languages and both jurisdictions', function (): void {
    $count = copilotGlobalChunks();

    $this->seed(CopilotRegulatoryKnowledgeSeeder::class);

    expect(copilotGlobalChunks())->toBe($count)
        ->and($count % 4)->toBe(0);

    $chunks = app(TenantContext::class)->runAsGlobal(static fn () => CopilotKnowledgeChunk::query()->whereNull('tenant_id')->get());

    expect($chunks->pluck('locale')->unique()->sort()->values()->all())->toBe(['de', 'en', 'fr', 'it'])
        ->and($chunks->pluck('jurisdiction')->unique()->sort()->values()->all())->toBe(['CH', 'IT'])
        ->and($chunks->every(fn (CopilotKnowledgeChunk $chunk): bool => is_array($chunk->embedding_vector) && $chunk->embedding_vector !== []))->toBeTrue();
});

test('a Swiss question finds the Swiss VAT passage with its citation badge', function (): void {
    $hits = app(KnowledgeRetriever::class)->retrieve($this->tenant->id, 'aliquota normale IVA svizzera', 'it-CH');

    expect($hits)->not->toBeEmpty()
        ->and(app(KnowledgeRetriever::class)->citation($hits[0]['chunk'])['label'])->toBe('[LIVA Art. 25]')
        ->and($hits[0]['chunk']->jurisdiction)->toBe('CH')
        ->and($hits[0]['chunk']->locale)->toBe('it');
});

test('an Italian question stays on Italian passages and in its language', function (): void {
    $retriever = app(KnowledgeRetriever::class);

    $german = $retriever->retrieve($this->tenant->id, 'Dokumenttyp Gutschrift TD04 FatturaPA', 'de');
    $italian = $retriever->retrieve($this->tenant->id, 'natura N6.9 inversione contabile altri casi', 'it-IT');

    expect($german[0]['chunk']->locale)->toBe('de')
        ->and($german[0]['chunk']->section_ref)->toBe('TD01–TD09')
        ->and($italian[0]['chunk']->jurisdiction)->toBe('IT')
        ->and($retriever->citation($italian[0]['chunk'])['label'])->toBe('[DPR 633/72 - N6.9]');

    foreach ($italian as $hit) {
        expect($hit['chunk']->jurisdiction)->toBeIn(['IT', 'GLOBAL']);
    }
});

test('the documents of a tenant are cited for that tenant only', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    app(IndexTenantKnowledgeRagCommand::class)->execute($this->tenant->id, [[
        'title' => 'Internal travel policy',
        'content' => 'Employees book flights through the Zurich travel desk and need approval above 800 francs.',
        'jurisdiction' => 'CH',
        'locale' => 'en',
    ]]);

    $own = app(KnowledgeRetriever::class)->retrieve($this->tenant->id, 'flights travel desk approval', 'en-CH');

    app(TenantContext::class)->setTenant($this->other);
    $foreign = app(KnowledgeRetriever::class)->retrieve($this->other->id, 'flights travel desk approval', 'en-CH');

    expect($own[0]['chunk']->document_title)->toBe('Internal travel policy')
        ->and($own[0]['chunk']->tenant_id)->toBe($this->tenant->id)
        ->and(array_map(static fn (array $hit): string => $hit['chunk']->document_title, $foreign))->not->toContain('Internal travel policy');
});

test('indexing a tenant document again updates it instead of duplicating it', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $document = ['title' => 'Policy', 'content' => 'First version of the policy.', 'jurisdiction' => 'CH', 'locale' => 'en'];

    app(IndexTenantKnowledgeRagCommand::class)->execute($this->tenant->id, [$document]);
    app(IndexTenantKnowledgeRagCommand::class)->execute($this->tenant->id, [[...$document, 'content' => 'Second version of the policy.']]);

    $chunks = CopilotKnowledgeChunk::query()->where('tenant_id', $this->tenant->id)->get();

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->content_text)->toBe('Second version of the policy.');
});
