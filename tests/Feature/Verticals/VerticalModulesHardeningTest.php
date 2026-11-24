<?php

declare(strict_types=1);

use App\Models\PosSession;
use App\Models\PosTerminal;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Childcare\Mail\FamilyAccessMail;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

/**
 * Every route of the workshop, construction trades, childcare, practice management and hospitality modules that a
 * company user reaches with a bearer token (the family portal has its own token and is tested separately).
 *
 * @return list<array{methods: list<string>, uri: string}>
 */
function verticalCompanyRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        $uri = $route->uri();
        $isVertical = str_starts_with($uri, 'api/v1/workshop/') || str_starts_with($uri, 'api/v1/automotive/') || str_starts_with($uri, 'api/v1/hospitality/')
            || str_starts_with($uri, 'api/v1/childcare/') || str_starts_with($uri, 'api/v1/practice/') || str_starts_with($uri, 'api/v1/construction-trades/')
            || str_starts_with($uri, 'api/v1/construction/');

        if ($isVertical) {
            $routes[] = ['methods' => array_values(array_diff($route->methods(), ['HEAD'])), 'uri' => $uri];
        }
    }

    return $routes;
}

function verticalUrl(string $uri): string
{
    return '/'.(string) preg_replace_callback('/\{[^}]+\}/', static fn (): string => (string) Str::uuid(), $uri);
}

test('read-only roles cannot write anywhere in the vertical modules, and childcare stays closed to them', function (): void {
    $tenant = verticalTenant();
    verticalApi(verticalMember($tenant, 'accountant_readonly'), $tenant);
    $routes = verticalCompanyRoutes();
    expect(count($routes))->toBeGreaterThan(80);

    foreach ($routes as $route) {
        $method = $route['methods'][0];
        $childcare = str_starts_with($route['uri'], 'api/v1/childcare/');

        if ($method === 'GET' && ! $childcare) {
            continue;
        }

        $status = $this->json($method, verticalUrl($route['uri']), [])->getStatusCode();
        expect($status)->toBe(403, "{$method} {$route['uri']} answered {$status} to a read-only role");
    }
});

test('every vertical endpoint answers a company admin without a server error, even on empty input and unknown records', function (): void {
    $tenant = verticalTenant();
    verticalApi(verticalMember($tenant, 'admin'), $tenant);

    foreach (verticalCompanyRoutes() as $route) {
        $method = $route['methods'][0];
        $status = $this->json($method, verticalUrl($route['uri']), [])->getStatusCode();

        expect($status)->toBeIn([200, 201, 204, 404, 422], "{$method} {$route['uri']} answered {$status}");
    }
});

test('the family portal refuses a missing or unknown token and is throttled', function (): void {
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/childcare-family/')) {
            continue;
        }

        $method = $route->methods()[0];
        $url = verticalUrl($route->uri());

        expect($this->json($method, $url, [])->getStatusCode())->toBe(401)
            ->and($this->withHeader('X-Family-Token', 'not-a-token')->json($method, $url, [])->getStatusCode())->toBe(401)
            ->and(collect($route->gatherMiddleware())->contains(static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')))->toBeTrue();
    }
});

describe('hospitality orders', function (): void {
    beforeEach(function (): void {
        $this->tenant = verticalTenant();
        $this->manager = verticalMember($this->tenant, 'admin');
        $this->waiter = verticalMember($this->tenant, 'member');
        verticalApi($this->manager, $this->tenant);
        $room = $this->postJson('/api/v1/hospitality/rooms', ['name' => 'Sala'])->assertCreated()->json('data.id');
        $this->table = $this->postJson("/api/v1/hospitality/rooms/{$room}/tables", ['name' => 'T1', 'seats' => 4])->assertCreated()->json('data.id');
        $this->spare = $this->postJson("/api/v1/hospitality/rooms/{$room}/tables", ['name' => 'T2', 'seats' => 4])->assertCreated()->json('data.id');
        $this->order = $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 2])->assertCreated()->json('data.id');
        $this->item = $this->postJson("/api/v1/hospitality/orders/{$this->order}/items", ['items' => [['description' => 'Acqua', 'quantity' => '1', 'unit_price' => '5', 'tax_rate' => '8.1']]])->assertCreated()->json('data.0.id');
    });

    test('a manager cancels an unpaid order, freeing the table, and waiters cannot', function (): void {
        verticalApi($this->waiter, $this->tenant);
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", ['reason' => 'Tavolo sbagliato'])->assertForbidden();

        verticalApi($this->manager, $this->tenant);
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", ['reason' => 'Tavolo sbagliato'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", ['reason' => 'Ancora'])->assertUnprocessable();
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/items", ['items' => [['description' => 'Vino', 'quantity' => '1', 'unit_price' => '9', 'tax_rate' => '8.1']]])->assertUnprocessable();
        expect(DB::table('hosp_order_items')->where('order_id', $this->order)->where('status', '!=', 'voided')->count())->toBe(0);

        $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 2])->assertCreated();
    });

    test('voiding the last item cancels the order, and an item is voided only once', function (): void {
        $this->postJson("/api/v1/hospitality/order-items/{$this->item}/void", ['reason' => 'Errore'])->assertOk();
        $this->postJson("/api/v1/hospitality/order-items/{$this->item}/void", ['reason' => 'Errore'])->assertUnprocessable();
        $this->getJson("/api/v1/hospitality/orders/{$this->order}")->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 1])->assertCreated();
    });

    test('an order moves only to a table in service and other companies cannot cancel it', function (): void {
        $this->putJson("/api/v1/hospitality/tables/{$this->spare}", ['is_active' => false])->assertOk();
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/move", ['table_id' => $this->spare])->assertUnprocessable();

        $other = verticalTenant();
        verticalApi(verticalMember($other, 'admin'), $other);
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", ['reason' => 'Intruso'])->assertNotFound();
    });

    test('an order with a payment cannot be cancelled', function (): void {
        app(TenantContext::class)->setTenant($this->tenant);
        $terminal = PosTerminal::create(['terminal_code' => 'T9', 'name' => 'Cassa', 'is_active' => true]);
        $session = PosSession::create(['pos_terminal_id' => $terminal->id, 'user_id' => $this->waiter->id, 'opened_at' => now(), 'opening_float' => 0, 'status' => 'open']);
        verticalApi($this->manager, $this->tenant);
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/payments", ['pos_session_id' => $session->id, 'mode' => 'equal', 'ways' => 2, 'payment_method' => 'cash'])->assertCreated();
        $this->postJson("/api/v1/hospitality/orders/{$this->order}/cancel", ['reason' => 'Troppo tardi'])->assertUnprocessable();
    });
});

test('a checklist key that is not part of the closing is refused', function (): void {
    $tenant = verticalTenant();
    $client = verticalContact($tenant);
    verticalApi(verticalMember($tenant, 'admin'), $tenant);
    $closing = $this->postJson('/api/v1/practice/closings', [
        'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'checklist' => [['key' => 'vat', 'label' => 'IVA']],
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/practice/closings/{$closing}/checklist", ['items' => [['key' => 'typo', 'done' => true]]])->assertUnprocessable();
    $this->putJson("/api/v1/practice/closings/{$closing}/checklist", ['items' => [['key' => 'vat', 'done' => true]]])->assertOk();
});

test('child documents are served as attachments with sniffing disabled, to staff and to the guardian only', function (): void {
    Mail::fake();
    Storage::fake('private');
    $tenant = verticalTenant();
    $guardian = verticalContact($tenant, 'Famiglia Rossi');
    verticalApi(verticalMember($tenant, 'childcare_manager'), $tenant);
    $child = $this->postJson('/api/v1/childcare/children', [
        'billing_contact_id' => $guardian->id, 'first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => '2023-04-12', 'enrolled_on' => '2026-09-01',
    ])->assertCreated()->json('data.id');
    $person = $this->postJson("/api/v1/childcare/children/{$child}/reference-persons", [
        'full_name' => 'Anna Rossi', 'email' => 'anna@example.ch', 'relationship' => 'mother', 'is_guardian' => true, 'can_pick_up' => true, 'portal_contact_id' => $guardian->id,
    ])->assertCreated()->json('data.id');
    $document = $this->postJson("/api/v1/childcare/children/{$child}/documents", ['title' => 'Vaccini', 'file' => UploadedFile::fake()->create('vaccini.pdf', 20, 'application/pdf')])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/childcare/children/{$child}/documents", ['title' => 'Script', 'file' => UploadedFile::fake()->create('x.html', 2, 'text/html')])->assertUnprocessable();

    $download = $this->get("/api/v1/childcare/documents/{$document}/download")->assertOk();
    expect($download->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->postJson("/api/v1/childcare/reference-persons/{$person}/portal-access")->assertAccepted();
    $token = null;
    Mail::assertSent(FamilyAccessMail::class, function (FamilyAccessMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    test()->flushHeaders();
    app('auth')->forgetGuards();
    $family = $this->withHeader('X-Family-Token', (string) $token)->get("/api/v1/childcare-family/documents/{$document}/download")->assertOk();
    expect($family->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'childcare_manager'), $other);
    $this->get("/api/v1/childcare/documents/{$document}/download")->assertNotFound();
});
