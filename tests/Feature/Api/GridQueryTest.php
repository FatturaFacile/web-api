<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/** Contact with an accessor that only exists in PHP. */
class GridAccessorContact extends Contact
{
    protected $table = 'contacts';

    public function getInitialAttribute(): string
    {
        return strtoupper(substr((string) $this->company_name, 0, 1));
    }
}

/**
 * @param  Builder<*>  $query
 * @param  array<string, mixed>  $parameters
 */
function gridPage(Builder $query, array $parameters, int $perPage = 10): LengthAwarePaginator
{
    app()->instance('request', Request::create('/grid', 'GET', $parameters));

    return $query->gridPaginate($perPage);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant, 'accountant');

    foreach (['Bravo Srl', 'Alfa Spa', 'Charlie Snc'] as $name) {
        Contact::factory()->create(['company_name' => $name, 'type' => 'client']);
    }
});

test('a grid list honours per_page and server-side sorting', function (): void {
    $response = $this->getJson('/api/v1/crm/contacts?per_page=2&sort_by=company_name&sort_dir=desc')->assertOk();

    expect(collect($response->json('data.data'))->pluck('company_name')->all())->toBe(['Charlie Snc', 'Bravo Srl'])
        ->and($response->json('data.per_page'))->toBe(2)
        ->and($response->json('data.total'))->toBe(3);
});

test('a grid list filters by value list and by text on real columns only', function (): void {
    $byList = $this->getJson('/api/v1/crm/contacts?filter[company_name][]=Alfa%20Spa&filter[company_name][]=Bravo%20Srl&sort_by=company_name')->assertOk();
    expect(collect($byList->json('data.data'))->pluck('company_name')->all())->toBe(['Alfa Spa', 'Bravo Srl']);

    $byText = $this->getJson('/api/v1/crm/contacts?filter_like[company_name]=CHAR')->assertOk();
    expect($byText->json('data.total'))->toBe(1);

    $unknownColumn = $this->getJson('/api/v1/crm/contacts?sort_by=drop_table&filter_like[nope]=x')->assertOk();
    expect($unknownColumn->json('data.total'))->toBe(3);
});

test('a grid column can be a relation path, evaluated in SQL: filter, sort, group and values', function (): void {
    foreach (Contact::query()->orderBy('company_name')->get() as $index => $contact) {
        foreach (range(1, $index + 1) as $n) {
            ContactAddress::query()->create([
                'contact_id' => $contact->id, 'type' => 'billing', 'street' => "Via {$n}", 'postal_code' => '20100', 'city' => 'Milano', 'country' => 'IT',
            ]);
        }
    }
    $page = static fn (array $query, int $perPage = 10) => gridPage(ContactAddress::query(), $query, $perPage);

    $sorted = $page(['sort_by' => 'contact.company_name', 'sort_dir' => 'desc']);
    expect($sorted->total())->toBe(6)
        ->and($sorted->getCollection()->first()->contact->company_name)->toBe('Charlie Snc');

    $filtered = $page(['filter' => ['contact.company_name' => ['Bravo Srl']]]);
    expect($filtered->total())->toBe(2);

    $like = $page(['filter_like' => ['contact.company_name' => 'CHAR']]);
    expect($like->total())->toBe(3);

    // Grouping orders the WHOLE dataset, not only the page: page 1 (size 2) only holds the first group.
    $grouped = $page(['grid_group_by' => 'contact.company_name'], 2);
    expect($grouped->getCollection()->map(fn ($a) => $a->contact->company_name)->unique()->all())->toBe(['Alfa Spa', 'Bravo Srl']);
});

test('the filter list values come from the whole dataset, not from the page', function (): void {
    foreach (range(1, 30) as $n) {
        Contact::factory()->create(['company_name' => sprintf('Company %02d', $n), 'type' => 'client']);
    }

    $response = $this->getJson('/api/v1/crm/contacts?per_page=5&grid_values=company_name')->assertOk();

    expect($response->json('data'))->toHaveCount(33)
        ->and($response->json('data.0'))->toBe(['value' => 'Alfa Spa', 'count' => 1])
        ->and($response->json('meta.truncated'))->toBeFalse();

    // Group headers: counts of the filtered dataset.
    $groups = $this->getJson('/api/v1/crm/contacts?grid_values=company_name&grid_scope=filtered&filter_like[company_name]=Company%200')->assertOk();
    expect($groups->json('data'))->toHaveCount(9);
});

test('an empty cell can be selected in a value list', function (): void {
    Contact::factory()->create(['company_name' => null, 'type' => 'client', 'first_name' => 'Mario', 'last_name' => 'Rossi']);

    $values = $this->getJson('/api/v1/crm/contacts?grid_values=company_name')->assertOk()->json('data');
    expect(collect($values)->last()['value'])->toBeNull();

    $empty = $this->getJson('/api/v1/crm/contacts?filter[company_name][]=__empty__')->assertOk();
    expect($empty->json('data.total'))->toBe(1);
});

test('a model accessor is a grid column too (evaluated in PHP) and unsafe names are ignored', function (): void {
    $page = static fn (array $query) => gridPage(GridAccessorContact::query(), $query);

    $sorted = $page(['sort_by' => 'initial', 'sort_dir' => 'desc']);
    expect($sorted->getCollection()->pluck('company_name')->all())->toBe(['Charlie Snc', 'Bravo Srl', 'Alfa Spa']);

    expect($page(['filter' => ['initial' => ['B']]])->total())->toBe(1)
        ->and($page(['filter_like' => ['initial' => 'c']])->total())->toBe(1);

    // Names that are not columns, accessors or typed relations never reach the model.
    expect($page(['sort_by' => 'delete', 'filter' => ['forceDelete.id' => ['1']]])->total())->toBe(3);
});

test('a calculated column declared by the model works like a real column', function (): void {
    Contact::factory()->create(['company_name' => null, 'first_name' => 'Zeno', 'last_name' => 'Rossi', 'type' => 'client']);

    $sorted = $this->getJson('/api/v1/crm/contacts?sort_by=name&sort_dir=desc&per_page=2')->assertOk();
    expect($sorted->json('data.data.0.first_name'))->toBe('Zeno');

    $values = collect($this->getJson('/api/v1/crm/contacts?grid_values=name')->assertOk()->json('data'))->pluck('value')->all();
    expect($values)->toBe(['Alfa Spa', 'Bravo Srl', 'Charlie Snc', 'Zeno Rossi']);

    expect($this->getJson('/api/v1/crm/contacts?filter[name][]=Zeno%20Rossi')->assertOk()->json('data.total'))->toBe(1)
        ->and($this->getJson('/api/v1/crm/contacts?filter_like[name]=ROSSI')->assertOk()->json('data.total'))->toBe(1);
});

test('calculated columns of the model compile on the database', function (): void {
    expect($this->getJson('/api/v1/crm/contacts?grid_values=tax_id')->assertOk()->json('data'))->toBeArray()
        ->and($this->getJson('/api/v1/sales/invoices?grid_values=display_status')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/sales/invoices?sort_by=display_status&filter[display_status][]=overdue&grid_group_by=display_status')->assertOk()->json('meta.total'))->toBe(0);
});
