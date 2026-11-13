<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\LookupItem;
use Database\Seeders\AppointmentLookupSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationTemplate;
use Modules\HealthExpenses\Models\HealthExpense;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/**
 * @param  Builder<*>  $query
 * @param  array<string, mixed>  $parameters
 */
function fieldGridPage(Builder $query, array $parameters, int $perPage = 10): LengthAwarePaginator
{
    app()->instance('request', Request::create('/grid', 'GET', $parameters));

    return $query->gridPaginate($perPage);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->member = actingAsMember($this->tenant);
});

test('the lifecycle status of an appointment is a calculated grid column: values, filter and sort', function (): void {
    (new AppointmentLookupSeeder)->run();
    $references = [
        'tenant_id' => $this->tenant->id,
        'status_id' => LookupItem::query()->value('id'),
        'contact_id' => Contact::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'created_by' => $this->member->id,
        'updated_by' => $this->member->id,
    ];
    $make = fn (array $attributes) => Appointment::factory()->create([...$references, ...$attributes]);
    $make(['title' => 'Scheduled']);
    $make(['title' => 'Confirmed', 'confirmed_at' => now()]);
    $make(['title' => 'Cancelled', 'cancelled_at' => now(), 'confirmed_at' => now()]);
    $make(['title' => 'Done', 'service_report_id' => (string) Str::uuid()]);

    $values = $this->getJson('/api/v1/appointments?grid_values=lifecycle_status')->assertOk()->json('data');
    expect(collect($values)->pluck('count', 'value')->all())->toBe(['cancelled' => 1, 'completed' => 1, 'confirmed' => 1, 'scheduled' => 1]);

    $filtered = fieldGridPage(Appointment::query(), ['filter' => ['lifecycle_status' => ['cancelled', 'completed']]]);
    expect($filtered->getCollection()->pluck('title')->sort()->values()->all())->toBe(['Cancelled', 'Done']);

    $sorted = fieldGridPage(Appointment::query(), ['sort_by' => 'lifecycle_status', 'sort_dir' => 'asc']);
    expect($sorted->getCollection()->pluck('title')->all())->toBe(['Cancelled', 'Done', 'Confirmed', 'Scheduled']);
});

test('the transmission status of a health expense is a calculated grid column and part of the payload', function (): void {
    foreach ([['A1', false], ['A2', false], ['A3', true]] as [$number, $opposed]) {
        HealthExpense::factory()->create([
            'tenant_id' => $this->tenant->id,
            'document_id' => (string) Str::uuid(),
            'document_number' => $number,
            'patient_opposition' => $opposed,
        ]);
    }

    $values = $this->getJson('/api/v1/tessera-sanitaria/expenses?grid_values=transmission_status')->assertOk()->json('data');
    expect(collect($values)->pluck('count', 'value')->all())->toBe(['opposed' => 1, 'pending' => 2]);

    $opposedOnly = $this->getJson('/api/v1/tessera-sanitaria/expenses?filter[transmission_status][]=opposed')->assertOk();
    expect($opposedOnly->json('data'))->toHaveCount(1)
        ->and($opposedOnly->json('data.0.document_number'))->toBe('A3')
        ->and($opposedOnly->json('data.0.transmission_status'))->toBe('opposed');

    $sorted = $this->getJson('/api/v1/tessera-sanitaria/expenses?sort_by=transmission_status&sort_dir=asc&per_page=1')->assertOk();
    expect($sorted->json('data.0.document_number'))->toBe('A3')
        ->and($sorted->json('meta.total'))->toBe(3);
});

test('the communication templates list is a paginated grid', function (): void {
    foreach (range(1, 6) as $n) {
        CommunicationTemplate::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => sprintf('code.%02d', $n),
            'channel' => $n % 2 === 0 ? 'sms' : 'email',
            'locale' => 'en',
            'subject' => $n % 2 === 0 ? null : 'Subject',
            'body' => 'Body {{ number }}',
            'is_active' => $n !== 6,
        ]);
    }

    $page = $this->getJson('/api/v1/communication/templates?per_page=4&sort_by=code&sort_dir=desc')->assertOk();
    expect($page->json('data'))->toHaveCount(4)
        ->and($page->json('data.0.code'))->toBe('code.06')
        ->and($page->json('meta.total'))->toBe(6)
        ->and($page->json('meta.last_page'))->toBe(2);

    $values = $this->getJson('/api/v1/communication/templates?grid_values=channel')->assertOk()->json('data');
    expect(collect($values)->pluck('count', 'value')->all())->toBe(['email' => 3, 'sms' => 3]);

    $inactive = $this->getJson('/api/v1/communication/templates?filter[is_active][]=0')->assertOk();
    expect($inactive->json('data'))->toHaveCount(1);
});
