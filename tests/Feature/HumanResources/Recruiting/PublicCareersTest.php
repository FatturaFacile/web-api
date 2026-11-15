<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\HumanResources\Models\JobOpening;

use function Tests\Feature\HumanResources\Recruiting\careersSettings;
use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

test('public jobs list only published openings of the slug tenant, localised with fallback to en', function () {
    $tenant = Tenant::factory()->create();
    $settings = careersSettings($tenant, ['careers_slug' => 'acme-ticino']);

    $german = publishedOpening($tenant, [
        'published_languages' => ['it-CH', 'de-CH', 'en'],
        'translations' => [
            'it-CH' => ['title' => 'Sviluppatore', 'description' => 'Descrizione'],
            'de-CH' => ['title' => 'Entwickler', 'description' => 'Beschreibung'],
            'en' => ['title' => 'Developer', 'description' => 'Description'],
        ],
    ]);
    $englishOnly = publishedOpening($tenant, [
        'published_languages' => ['it-CH', 'en'],
        'translations' => [
            'it-CH' => ['title' => 'Magazziniere', 'description' => 'Descrizione'],
            'en' => ['title' => 'Warehouse clerk', 'description' => 'Description'],
        ],
    ]);
    publishedOpening($tenant, ['status' => 'internal_only']);
    publishedOpening($tenant, ['status' => 'draft', 'published_at' => null]);
    publishedOpening($tenant, ['status' => 'closed_filled']);

    $otherTenant = Tenant::factory()->create();
    careersSettings($otherTenant, ['careers_slug' => 'other-company']);
    publishedOpening($otherTenant);

    $response = $this->getJson('/api/v1/public/careers/acme-ticino/jobs?locale=de-CH');

    $response->assertOk()->assertJsonPath('meta.total', 2);
    $jobs = collect($response->json('data'))->keyBy('id');

    expect($jobs->keys()->all())->toEqualCanonicalizing([$german->id, $englishOnly->id])
        ->and($jobs[$german->id]['title'])->toBe('Entwickler')
        ->and($jobs[$german->id]['locale'])->toBe('de-CH')
        ->and($jobs[$englishOnly->id]['title'])->toBe('Warehouse clerk')
        ->and($jobs[$englishOnly->id]['locale'])->toBe('en')
        ->and($jobs[$german->id]['apply_url'])->toEndWith("/careers/acme-ticino/jobs/{$german->id}")
        ->and($jobs[$german->id])->not->toHaveKey('applications_count');

    expect($settings->careers_slug)->toBe('acme-ticino');
});

test('public job detail is 404 for internal, draft and foreign openings', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $internal = publishedOpening($tenant, ['status' => 'internal_only']);
    $published = publishedOpening($tenant);

    $otherTenant = Tenant::factory()->create();
    careersSettings($otherTenant, ['careers_slug' => 'other-company']);
    $foreign = publishedOpening($otherTenant);

    $this->getJson("/api/v1/public/careers/acme-ticino/jobs/{$published->id}?locale=it-CH")
        ->assertOk()
        ->assertJsonPath('data.title', 'Sviluppatore PHP')
        ->assertJsonPath('data.available_locales', ['it-CH', 'en']);
    $this->getJson("/api/v1/public/careers/acme-ticino/jobs/{$internal->id}")->assertNotFound();
    $this->getJson("/api/v1/public/careers/acme-ticino/jobs/{$foreign->id}")->assertNotFound();
});

test('unknown or disabled careers slugs return 404', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'closed-careers', 'careers_enabled' => false]);

    $this->getJson('/api/v1/public/careers/closed-careers/jobs')->assertNotFound();
    $this->getJson('/api/v1/public/careers/does-not-exist')->assertNotFound();
});

test('careers page meta exposes localised texts and consent version', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, [
        'careers_slug' => 'acme-ticino',
        'intro' => ['en' => 'Join us', 'it-CH' => 'Unisciti a noi'],
        'consent_text_version' => '2026-11',
        'retention_default_months' => 12,
    ]);
    publishedOpening($tenant);

    $this->getJson('/api/v1/public/careers/acme-ticino?locale=it-CH')
        ->assertOk()
        ->assertJsonPath('data.company_name', 'Acme Ticino SA')
        ->assertJsonPath('data.intro', 'Unisciti a noi')
        ->assertJsonPath('data.consent_text_version', '2026-11')
        ->assertJsonPath('data.retention_months', 12)
        ->assertJsonPath('data.available_locales', ['it-CH', 'en']);
});

test('json and rss feeds list exactly the published openings with escaped content and no cross-tenant leak', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $opening = publishedOpening($tenant, [
        'published_languages' => ['en'],
        'translations' => [
            'en' => ['title' => 'R&D <Engineer> "Lead"', 'description' => "Build <b>things</b> & more ]]> end"],
        ],
    ]);
    publishedOpening($tenant, ['status' => 'internal_only']);

    $otherTenant = Tenant::factory()->create();
    careersSettings($otherTenant, ['careers_slug' => 'other-company']);
    $foreign = publishedOpening($otherTenant);

    $json = $this->getJson('/api/v1/public/careers/acme-ticino/feed.json');
    $json->assertOk()->assertJsonPath('version', '1.0')->assertJsonCount(1, 'jobs');
    expect($json->json('jobs.0.id'))->toBe($opening->id)
        ->and($json->json('jobs.0.title'))->toBe('R&D <Engineer> "Lead"')
        ->and($json->json('jobs.0.description_html'))->toContain('&lt;b&gt;things&lt;/b&gt;');

    $rss = $this->get('/api/v1/public/careers/acme-ticino/feed.rss');
    $rss->assertOk();
    expect($rss->headers->get('Content-Type'))->toContain('application/rss+xml');

    $xml = simplexml_load_string((string) $rss->getContent());
    expect($xml)->not->toBeFalse();
    $items = $xml->channel->item;
    expect(count($items))->toBe(1)
        ->and((string) $items[0]->title)->toBe('R&D <Engineer> "Lead"')
        ->and((string) $items[0]->guid)->toBe($opening->id.'-en')
        ->and((string) $items[0]->children('urn:fatturafacile:jobs')->location)->toBe('Lugano')
        ->and((string) $rss->getContent())->not->toContain($foreign->id);

    $foreignFeed = $this->getJson('/api/v1/public/careers/other-company/feed.json');
    expect(collect($foreignFeed->json('jobs'))->pluck('id')->all())->toBe([$foreign->id]);
});

test('json feed emits one entry per published language when no locale is requested', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    publishedOpening($tenant);

    $response = $this->getJson('/api/v1/public/careers/acme-ticino/feed.json');

    $response->assertOk()->assertJsonCount(2, 'jobs');
    expect(collect($response->json('jobs'))->pluck('locale')->all())->toEqualCanonicalizing(['it-CH', 'en']);

    useTenant($tenant);
    expect(JobOpening::query()->count())->toBe(1);
});
