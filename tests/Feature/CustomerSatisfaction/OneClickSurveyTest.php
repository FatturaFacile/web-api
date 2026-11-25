<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\SurveyResponse;

require_once __DIR__.'/CustomerSatisfactionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    csSetUp($this);
    $this->template = csTemplate();
    $this->dispatch = csDispatch($this->template, $this->customer);
    $this->base = '/api/v1/public/surveys/'.$this->dispatch->dispatch_token;
});

it('stores the score of the first click at once, even if no comment ever follows', function (): void {
    $this->getJson($this->base.'/rate/9')->assertOk()->assertJsonPath('data.score', 9)->assertJsonPath('data.normalized_band', 'promoter');

    $response = SurveyResponse::query()->sole();

    expect($response->score)->toBe(9)
        ->and($response->is_detractor)->toBeFalse()
        ->and($response->submitted_via->value)->toBe('one_click_link')
        ->and($response->initial_clicked_at)->not->toBeNull()
        ->and($response->comment_text)->toBeNull()
        ->and($response->comment_updated_at)->toBeNull()
        ->and($response->department_code)->toBe('support')
        ->and($response->branch_code)->toBe('lugano')
        ->and($this->dispatch->refresh()->status->value)->toBe('responded')
        ->and($this->dispatch->responded_at)->not->toBeNull()
        ->and(DetractorRecoveryCase::query()->count())->toBe(0);
});

it('sends the browser of an email click on to the mobile landing page after storing the score', function (): void {
    $this->get($this->base.'/rate/7')->assertRedirect(rtrim((string) config('customer_satisfaction.landing_url'), '/').'/'.$this->dispatch->dispatch_token);

    expect(SurveyResponse::query()->sole()->score)->toBe(7);
});

it('keeps the first answer when the link is clicked again', function (): void {
    $this->getJson($this->base.'/rate/9')->assertOk();
    $this->getJson($this->base.'/rate/2')->assertOk()->assertJsonPath('data.score', 9);

    expect(SurveyResponse::query()->count())->toBe(1);
});

it('refuses a score outside the scale of the instrument', function (): void {
    $csat = csTemplate(['survey_type' => 'csat', 'code' => 'CSAT-X']);
    $dispatch = csDispatch($csat, $this->customer);

    $this->getJson('/api/v1/public/surveys/'.$dispatch->dispatch_token.'/rate/7')->assertStatus(422)->assertJsonPath('code', 'invalid_score');
    $this->getJson($this->base.'/rate/11')->assertStatus(422)->assertJsonPath('code', 'invalid_score');
    $this->getJson($this->base.'/rate/0')->assertOk()->assertJsonPath('data.normalized_band', 'detractor');
});

it('adds a text comment and a voice note to the answer already recorded, without a second answer', function (): void {
    Storage::fake('local');
    $this->getJson($this->base.'/rate/3')->assertOk();

    $this->post($this->base.'/feedback', [
        'comment_text' => 'The delivery was late and the support was slow. Very bad.',
        'voice' => UploadedFile::fake()->create('note.webm', 40, 'audio/webm'),
        'voice_duration_seconds' => 14,
        'voice_transcript' => 'Late delivery, nobody called back',
    ], ['Accept' => 'application/json'])->assertOk();

    $response = SurveyResponse::query()->sole();

    expect($response->comment_text)->toContain('late')
        ->and($response->comment_updated_at)->not->toBeNull()
        ->and($response->initial_clicked_at)->not->toBeNull()
        ->and($response->voice_attachment_path)->not->toBeNull()
        ->and($response->voice_duration_seconds)->toBe(14)
        ->and($response->voice_transcript)->toContain('Late delivery')
        ->and($response->sentiment_label)->toBe('negative')
        ->and($response->sentiment_topics)->toContain('delivery');

    Storage::disk('local')->assertExists($response->voice_attachment_path);
});

it('needs a score before a comment and a comment or a voice note in the follow-up', function (): void {
    $this->postJson($this->base.'/feedback', ['comment_text' => 'Hello'])->assertStatus(409)->assertJsonPath('code', 'score_required');

    $this->getJson($this->base.'/rate/8')->assertOk();

    $this->postJson($this->base.'/feedback', [])->assertStatus(422)->assertJsonPath('code', 'comment_required');
});

it('refuses a voice note when the template does not allow it', function (): void {
    Storage::fake('local');
    $template = csTemplate(['allow_voice_comment' => false, 'code' => 'NOVOICE']);
    $dispatch = csDispatch($template, $this->customer);
    $base = '/api/v1/public/surveys/'.$dispatch->dispatch_token;
    $this->getJson($base.'/rate/8')->assertOk();

    $this->post($base.'/feedback', ['voice' => UploadedFile::fake()->create('note.webm', 10, 'audio/webm')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('code', 'voice_not_allowed');
});

it('shows the landing page the follow-up question that matches the score, in the language of the survey', function (): void {
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.answered', false)->assertJsonPath('data.prompt', null)->assertJsonPath('data.scale.max', 10);

    $this->getJson($this->base.'/rate/2')->assertOk();

    $this->getJson($this->base)->assertOk()
        ->assertJsonPath('data.answered', true)
        ->assertJsonPath('data.score', 2)
        ->assertJsonPath('data.band', 'detractor')
        ->assertJsonPath('data.prompt', 'What went wrong?')
        ->assertJsonPath('data.prompts.high', 'What did you like?');

    $italian = csDispatch($this->template, $this->customer, ['locale' => 'it-CH']);
    $this->getJson('/api/v1/public/surveys/'.$italian->dispatch_token)->assertOk()->assertJsonPath('data.prompts.low', 'Cosa è andato storto?');
});

it('lets the landing page record the score when the visitor came without a one-click link', function (): void {
    $this->postJson($this->base.'/respond', ['score' => 10])->assertCreated()->assertJsonPath('data.submitted_via', 'landing_page');
    $this->postJson($this->base.'/respond', ['score' => 1])->assertOk()->assertJsonPath('data.score', 10);
});

it('records the interactive reply of WhatsApp', function (): void {
    $this->postJson($this->base.'/whatsapp-reply', ['score' => 8])->assertCreated()->assertJsonPath('data.submitted_via', 'whatsapp_reply');
});

it('answers an unknown, throttled or expired link with a problem and never exposes another survey', function (): void {
    $this->getJson('/api/v1/public/surveys/'.str_repeat('x', 48))->assertNotFound()->assertJsonPath('code', 'survey_not_found');

    $throttled = csDispatch($this->template, $this->customer, ['status' => 'throttled', 'throttle_reason' => 'cooldown_active']);
    $this->getJson('/api/v1/public/surveys/'.$throttled->dispatch_token.'/rate/9')->assertStatus(410)->assertJsonPath('code', 'survey_not_available');

    $old = csDispatch($this->template, $this->customer, ['sent_at' => now()->subDays(40), 'scheduled_for' => now()->subDays(41)]);
    $this->getJson('/api/v1/public/surveys/'.$old->dispatch_token.'/rate/9')->assertStatus(410)->assertJsonPath('code', 'survey_expired');

    expect($old->refresh()->status->value)->toBe('expired')
        ->and(SurveyResponse::query()->count())->toBe(0);
});

it('does not need a login or a tenant header for the public routes', function (): void {
    $this->app['auth']->forgetGuards();

    $this->withoutHeader('X-Tenant-Id')->getJson($this->base.'/rate/9')->assertOk();
});
