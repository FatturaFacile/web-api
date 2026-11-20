<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CopilotRegulatoryKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CopilotScenario;
use Tests\Support\ScriptedLlmProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->seed(CopilotRegulatoryKnowledgeSeeder::class);
    $this->user = CopilotScenario::member($this->tenant, 'owner');
    CopilotScenario::actAs($this, $this->user, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function copilotAsk(object $test, string $prompt, string $locale)
{
    $id = $test->postJson('/api/v1/copilot/conversations', ['locale' => $locale])->assertCreated()->json('data.id');

    return $test->postJson("/api/v1/copilot/conversations/{$id}/messages", ['prompt' => $prompt, 'locale' => $locale]);
}

test('the retrieved Swiss passage reaches the model as data and comes back as a citation badge', function (): void {
    $model = new ScriptedLlmProvider([ScriptedLlmProvider::says('The normal rate is 8.1% [LIVA Art. 25].')]);
    CopilotScenario::useModel($model);

    $data = copilotAsk($this, 'Qual è l\'aliquota normale IVA svizzera?', 'it-CH')->assertOk()->json('data');

    $system = $model->requests[0]->messages[0]->content;

    expect($system)->toContain('<knowledge_passage ref="[LIVA Art. 25]">')
        ->and($system)->toContain('8,1%')
        ->and($system)->toContain('Always answer in Italian')
        ->and($data['citations'][0])->toMatchArray(['type' => 'regulatory', 'label' => '[LIVA Art. 25]', 'jurisdiction' => 'CH']);
});

test('without any model configured the Copilot still answers a regulatory question from the cheat-sheets', function (): void {
    config(['copilot.driver' => 'local']);

    $data = copilotAsk($this, 'Qual è l\'aliquota normale IVA svizzera?', 'it-CH')->assertOk()->json('data');

    expect($data['content_markdown'])->toContain('[LIVA Art. 25]')
        ->and($data['content_markdown'])->toContain('8,1%')
        ->and($data['token_usage']['provider'])->toBe('local');
});

test('the language and the region of the locale decide which passages are retrieved', function (): void {
    $model = new ScriptedLlmProvider([ScriptedLlmProvider::says('ok'), ScriptedLlmProvider::says('ok')]);
    CopilotScenario::useModel($model);

    copilotAsk($this, 'VAT rate 22% reverse charge N6.9', 'en')->assertOk();
    copilotAsk($this, 'Mehrwertsteuer Normalsatz 8,1%', 'de-CH')->assertOk();

    expect($model->requests[0]->messages[0]->content)->toContain('DPR 633/72')
        ->and($model->requests[0]->messages[0]->content)->toContain('Always answer in English')
        ->and($model->requests[1]->messages[0]->content)->toContain('[LIVA Art. 25]')
        ->and($model->requests[1]->messages[0]->content)->not->toContain('DPR 633/72')
        ->and($model->requests[1]->messages[0]->content)->toContain('Always answer in German');
});
