<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ErpLookupsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ErpLookupsSeeder::class);
    }

    public function test_it_returns_lookups_in_default_italian(): void
    {
        $response = $this->getJson('/api/lookups/unit_of_measure');

        $response->assertOk();
        $items = $response->json();
        $this->assertNotEmpty($items);

        $nr = collect($items)->firstWhere('code', 'NR');
        $this->assertNotNull($nr);
        $this->assertSame('Numero', $nr['label']);
        $this->assertSame('nr', $nr['short_label']);
        $this->assertTrue($nr['is_default']);
    }

    public function test_it_returns_lookups_in_english_german_and_french(): void
    {
        // English
        $responseEn = $this->getJson('/api/lookups/unit_of_measure?locale=en');
        $responseEn->assertOk();
        $nrEn = collect($responseEn->json())->firstWhere('code', 'NR');
        $this->assertSame('Number', $nrEn['label']);

        // German
        $responseDe = $this->getJson('/api/lookups/unit_of_measure?locale=de');
        $responseDe->assertOk();
        $nrDe = collect($responseDe->json())->firstWhere('code', 'NR');
        $this->assertSame('Anzahl', $nrDe['label']);

        // French
        $responseFr = $this->getJson('/api/lookups/unit_of_measure?locale=fr');
        $responseFr->assertOk();
        $nrFr = collect($responseFr->json())->firstWhere('code', 'NR');
        $this->assertSame('Numéro', $nrFr['label']);
    }

    public function test_it_returns_404_for_unknown_category(): void
    {
        $response = $this->getJson('/api/lookups/non_existing_category');
        $response->assertNotFound();
    }

    public function test_it_supports_tenant_specific_translation_override(): void
    {
        $tenant = Tenant::create([
            'name' => 'Custom Tenant',
            'legal_name' => 'Custom Tenant SRL',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);
        $category = LookupCategory::where('code', 'unit_of_measure')->firstOrFail();
        $nrItem = LookupItem::where('category_id', $category->id)->where('code', 'NR')->firstOrFail();

        // Save a tenant-specific translation override
        LookupItemTranslation::create([
            'tenant_id' => $tenant->id,
            'lookup_item_id' => $nrItem->id,
            'locale' => 'it',
            'label' => 'Pezzi Custom',
            'short_label' => 'pz',
        ]);

        // Global query should still return "Numero"
        $globalResponse = $this->getJson('/api/lookups/unit_of_measure?locale=it');
        $globalNr = collect($globalResponse->json())->firstWhere('code', 'NR');
        $this->assertSame('Numero', $globalNr['label']);

        // Tenant query via header should return "Pezzi Custom"
        $tenantResponse = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/lookups/unit_of_measure?locale=it');
        $tenantNr = collect($tenantResponse->json())->firstWhere('code', 'NR');
        $this->assertSame('Pezzi Custom', $tenantNr['label']);
        $this->assertSame('pz', $tenantNr['short_label']);
    }

    public function test_it_saves_translation_via_api_and_clears_cache(): void
    {
        $tenant = Tenant::create([
            'name' => 'Custom Tenant',
            'legal_name' => 'Custom Tenant SRL',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, [
            'id' => (string) Str::uuid(),
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user);

        $category = LookupCategory::where('code', 'payment_type')->firstOrFail();
        $item = LookupItem::where('category_id', $category->id)->where('code', 'CASH')->firstOrFail();

        // Warm cache
        $this->getJson('/api/lookups/payment_type?locale=it');

        // Post custom translation
        $payload = [
            'lookup_item_id' => $item->id,
            'locale' => 'it',
            'label' => 'Contanti di Cassa',
            'short_label' => 'CASS',
            'tenant_id' => $tenant->id,
        ];

        $postResponse = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/lookups/translations', $payload);

        $postResponse->assertOk();
        $this->assertDatabaseHas('lookup_item_translations', [
            'tenant_id' => $tenant->id,
            'lookup_item_id' => $item->id,
            'locale' => 'it',
            'label' => 'Contanti di Cassa',
        ]);

        // Subsequent query should serve updated translation
        $fetchResponse = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/lookups/payment_type?locale=it');
        $cashItem = collect($fetchResponse->json())->firstWhere('code', 'CASH');
        $this->assertSame('Contanti di Cassa', $cashItem['label']);
    }

    public function test_saving_translations_requires_authentication(): void
    {
        $category = LookupCategory::where('code', 'payment_type')->firstOrFail();
        $item = LookupItem::where('category_id', $category->id)->where('code', 'CASH')->firstOrFail();
        $payload = ['lookup_item_id' => $item->id, 'locale' => 'it', 'label' => 'Overwritten'];

        $this->postJson('/api/v1/lookups/translations', $payload)->assertUnauthorized();
        $this->postJson('/api/lookups/translations', $payload)->assertStatus(405);

        $this->assertDatabaseMissing('lookup_item_translations', ['label' => 'Overwritten']);
    }
}
