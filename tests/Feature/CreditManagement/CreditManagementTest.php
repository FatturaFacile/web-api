<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = cmTenant('Distribuzione');
    $this->owner = cmMember($this->tenant, 'owner');
    cmAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function cmTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function cmMember(Tenant $tenant, string $role, array $permissions = []): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@credit-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    return $user;
}

function cmAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function cmCustomer(?Tenant $tenant = null, string $name = 'Acme'): Contact
{
    return Contact::factory()->create(['tenant_id' => ($tenant ?? test()->tenant)->id, 'company_name' => $name.' '.Str::random(4), 'type' => 'customer']);
}

function cmInvoice(Contact $customer, string $dueOn, string $gross = '1000.00', string $issuedOn = '2027-03-01', string $paid = '0.00'): SalesInvoice
{
    return SalesInvoice::factory()->create([
        'tenant_id' => $customer->tenant_id,
        'contact_id' => $customer->id,
        'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
        'status' => 'posted',
        'date' => $issuedOn,
        'due_date' => $dueOn,
        'total_gross' => $gross,
        'total_paid' => $paid,
        'payment_status' => $paid === '0.00' ? 'unpaid' : 'partially_paid',
    ]);
}

/**
 * Two factors (half the weight on how much is overdue, half on the analyst opinion) and the five default-like bands.
 */
function cmScorecard(): void
{
    test()->postJson('/api/v1/credit-management/scorecard/factors', ['code' => 'overdue', 'name' => 'Overdue', 'source' => 'overdue_ratio', 'weight' => 50])->assertCreated();
    test()->postJson('/api/v1/credit-management/scorecard/factors', ['code' => 'opinion', 'name' => 'Opinion', 'source' => 'manual', 'weight' => 50])->assertCreated();

    foreach ([['A', 85, 100, 'low', 2, 100000], ['B', 70, 84.99, 'low', 1.5, 50000], ['C', 50, 69.99, 'medium', 1, 20000], ['D', 30, 49.99, 'high', 1.5, 5000], ['E', 0, 29.99, 'critical', 0, 0]] as [$code, $min, $max, $risk, $multiplier, $ceiling]) {
        test()->postJson('/api/v1/credit-management/scorecard/bands', ['code' => $code, 'name' => 'Band '.$code, 'min_score' => $min, 'max_score' => $max, 'risk_level' => $risk, 'limit_multiplier' => $multiplier, 'limit_ceiling' => $ceiling])->assertCreated();
    }
}

function cmScore(Contact $customer, int $opinion = 80): TestResponse
{
    return test()->postJson('/api/v1/credit-management/scores', ['customer_id' => $customer->id, 'manual_inputs' => ['opinion' => $opinion]]);
}

test('the default scorecard is created once', function (): void {
    $this->postJson('/api/v1/credit-management/scorecard/defaults')->assertCreated()->assertJsonPath('data.factors', 5)->assertJsonPath('data.bands', 5);
    $this->postJson('/api/v1/credit-management/scorecard/defaults')->assertUnprocessable()->assertJsonValidationErrors('scorecard');
    $this->getJson('/api/v1/credit-management/scorecard/bands')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.code', 'E');
});

test('rating bands cannot overlap and factor codes are unique', function (): void {
    cmScorecard();

    $this->postJson('/api/v1/credit-management/scorecard/bands', ['code' => 'X', 'name' => 'Overlap', 'min_score' => 40, 'max_score' => 60, 'risk_level' => 'high', 'limit_multiplier' => 1, 'limit_ceiling' => 100])->assertUnprocessable()->assertJsonValidationErrors('min_score');
    $this->postJson('/api/v1/credit-management/scorecard/factors', ['code' => 'overdue', 'name' => 'Again', 'source' => 'manual', 'weight' => 10])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/credit-management/scorecard/factors', ['code' => 'bad', 'name' => 'Bad', 'source' => 'nonsense', 'weight' => 0])->assertUnprocessable()->assertJsonValidationErrors(['source', 'weight']);

    $band = $this->getJson('/api/v1/credit-management/scorecard/bands')->json('data.0');
    $this->putJson('/api/v1/credit-management/scorecard/bands/'.$band['id'], ['code' => 'E', 'name' => 'Critical', 'min_score' => 0, 'max_score' => 45, 'risk_level' => 'critical', 'limit_multiplier' => 0, 'limit_ceiling' => 0])->assertUnprocessable();
});

test('the score is the weighted average of the factors and lands in a rating band', function (): void {
    cmScorecard();
    $customer = cmCustomer();
    cmInvoice($customer, '2027-04-16', '1000.00');

    $response = cmScore($customer, 80)->assertCreated();

    $response->assertJsonPath('data.score', '40.00')->assertJsonPath('data.rating', 'D')->assertJsonPath('data.risk_level', 'high')->assertJsonCount(2, 'data.breakdown');
    expect(collect($response->json('data.breakdown'))->firstWhere('code', 'overdue'))->toMatchArray(['code' => 'overdue', 'raw_value' => '100.00', 'sub_score' => '0.00']);

    cmScore($customer, 100)->assertCreated()->assertJsonPath('data.score', '50.00')->assertJsonPath('data.rating', 'C');
    $this->getJson('/api/v1/credit-management/scores?customer_id='.$customer->id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.score', '50.00');
});

test('scoring needs the manual values and an active scorecard', function (): void {
    $customer = cmCustomer();
    cmScore($customer)->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    cmScorecard();
    $this->postJson('/api/v1/credit-management/scores', ['customer_id' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('manual_inputs.opinion');
    $this->postJson('/api/v1/credit-management/scores', ['customer_id' => $customer->id, 'manual_inputs' => ['opinion' => 140]])->assertUnprocessable();
});

test('the limit proposal is the band share of the quarterly turnover, capped and rounded down', function (): void {
    cmScorecard();
    $customer = cmCustomer();
    cmInvoice($customer, '2027-07-30', '4000.00', '2027-05-01');
    cmInvoice($customer, '2027-07-30', '1000.00', '2026-01-01');

    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/limit-proposal')->assertUnprocessable();

    cmScore($customer, 100)->assertCreated()->assertJsonPath('data.rating', 'A');

    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/limit-proposal')
        ->assertOk()
        ->assertJsonPath('data.quarterly_turnover', '1000.00')
        ->assertJsonPath('data.proposed_limit', '2000.00')
        ->assertJsonPath('data.rating', 'A');

    cmInvoice($customer, '2027-07-30', '32000.00', '2027-02-01');
    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/limit-proposal')->assertOk()->assertJsonPath('data.proposed_limit', '18000.00');

    cmInvoice($customer, '2027-07-30', '800000.00', '2027-02-02');
    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/limit-proposal')->assertOk()->assertJsonPath('data.proposed_limit', '100000.00');
});

test('a credit limit request is decided by another approver and stores the limit', function (): void {
    cmScorecard();
    $customer = cmCustomer();
    cmInvoice($customer, '2027-07-30', '4000.00', '2027-05-01');
    cmScore($customer, 100)->assertCreated();

    $request = $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id, 'reason' => 'Growing orders'])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.requested_limit', '2000.00')->assertJsonPath('data.proposed_limit', '2000.00')->json('data');
    $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/approve', [])->assertUnprocessable()->assertJsonValidationErrors('status');

    cmAct(cmMember($this->tenant, 'accountant'), $this->tenant);
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/approve', [])->assertForbidden();

    cmAct(cmMember($this->tenant, 'admin'), $this->tenant);
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/approve', ['approved_limit' => 5000])->assertUnprocessable()->assertJsonValidationErrors('decision_note');
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/approve', ['approved_limit' => 5000, 'decision_note' => 'Insured by a long relationship'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_limit', '5000.00');

    expect((string) CustomerCreditProfile::query()->where('customer_id', $customer->id)->value('credit_limit'))->toBe('5000.00');
    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/exposure')->assertOk()->assertJsonPath('data.credit_limit', '5000.00')->assertJsonPath('data.available_credit', '1000.00');
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/cancel')->assertUnprocessable();
});

test('a limit request can be rejected or cancelled and needs an amount without a score', function (): void {
    $customer = cmCustomer();
    $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('requested_limit');

    $request = $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id, 'requested_limit' => 3000])->assertCreated()->json('data');
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/reject', [])->assertUnprocessable()->assertJsonValidationErrors('decision_note');
    $this->postJson('/api/v1/credit-management/limit-requests/'.$request['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    $second = $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id, 'requested_limit' => 2000])->assertCreated()->json('data');
    cmAct(cmMember($this->tenant, 'admin'), $this->tenant);
    $this->postJson('/api/v1/credit-management/limit-requests/'.$second['id'].'/reject', ['decision_note' => 'Too risky'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->getJson('/api/v1/credit-management/limit-requests?status=rejected')->assertOk()->assertJsonCount(1, 'data');
    expect(CustomerCreditProfile::query()->where('customer_id', $customer->id)->exists())->toBeFalse();
});

test('credit insurance stays within the policy and shows in the exposure', function (): void {
    $customer = cmCustomer();
    $other = cmCustomer(null, 'Other');
    cmInvoice($customer, '2027-07-30', '3000.00');

    $policy = $this->postJson('/api/v1/credit-management/insurance-policies', ['insurer' => 'Coface', 'policy_number' => 'POL-1', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'currency' => 'EUR', 'coverage_percentage' => 90, 'max_total_cover' => 10000])->assertCreated()->json('data');
    $this->postJson('/api/v1/credit-management/insurance-policies', ['insurer' => 'X', 'policy_number' => 'P', 'starts_on' => '2027-05-01', 'ends_on' => '2027-04-01', 'currency' => 'EUR', 'coverage_percentage' => 90])->assertUnprocessable()->assertJsonValidationErrors('ends_on');

    $this->postJson('/api/v1/credit-management/insurance-policies/'.$policy['id'].'/covers', ['customer_id' => $customer->id, 'insured_limit' => 2000])->assertCreated()->assertJsonPath('data.status', 'granted')->assertJsonPath('data.expires_on', '2027-12-31');
    $this->postJson('/api/v1/credit-management/insurance-policies/'.$policy['id'].'/covers', ['customer_id' => $customer->id, 'insured_limit' => 100])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $this->postJson('/api/v1/credit-management/insurance-policies/'.$policy['id'].'/covers', ['customer_id' => $other->id, 'insured_limit' => 9000])->assertUnprocessable()->assertJsonValidationErrors('insured_limit');

    $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/exposure')->assertOk()->assertJsonPath('data.insured_limit', '2000.00')->assertJsonPath('data.uninsured_exposure', '1000.00');
    $this->getJson('/api/v1/credit-management/insurance-policies/'.$policy['id'])->assertOk()->assertJsonPath('data.granted_total', '2000.00')->assertJsonPath('data.is_in_force', true);
    $this->putJson('/api/v1/credit-management/insurance-policies/'.$policy['id'], [...$policy, 'max_total_cover' => 1000])->assertUnprocessable()->assertJsonValidationErrors('max_total_cover');
    $this->deleteJson('/api/v1/credit-management/insurance-policies/'.$policy['id'])->assertUnprocessable();

    $expired = $this->postJson('/api/v1/credit-management/insurance-policies', ['insurer' => 'Old', 'policy_number' => 'POL-0', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'currency' => 'EUR', 'coverage_percentage' => 80])->assertCreated()->json('data');
    $this->postJson('/api/v1/credit-management/insurance-policies/'.$expired['id'].'/covers', ['customer_id' => $other->id, 'insured_limit' => 100])->assertUnprocessable()->assertJsonValidationErrors('policy_id');
});

test('exposure compares open invoices and orders with the limit and ages the receivables', function (): void {
    $customer = cmCustomer();
    cmInvoice($customer, '2027-08-01', '1000.00');
    cmInvoice($customer, '2027-06-05', '500.00');
    cmInvoice($customer, '2027-05-01', '2000.00', '2027-03-01', '500.00');
    cmInvoice($customer, '2027-01-01', '300.00');
    CustomerCreditProfile::create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'credit_limit' => '3000.00']);

    $exposure = $this->getJson('/api/v1/credit-management/customers/'.$customer->id.'/exposure')->assertOk()->json('data');
    expect($exposure)->toMatchArray(['open_invoices' => '3300.00', 'overdue_amount' => '2300.00', 'overdue_invoices_count' => 3, 'max_days_overdue' => 165, 'exposure' => '3300.00', 'is_over_limit' => true, 'available_credit' => '-300.00']);

    $this->getJson('/api/v1/credit-management/exposures?over_limit_only=1')->assertOk()->assertJsonCount(1, 'data');

    $aging = $this->getJson('/api/v1/credit-management/aging')->assertOk()->json('data');
    $amounts = collect($aging['buckets'])->pluck('amount', 'key')->all();
    expect($amounts)->toBe(['current' => '1000.00', 'd1_30' => '500.00', 'd31_60' => '1500.00', 'd61_90' => '0.00', 'd90_plus' => '300.00'])->and($aging['total'])->toBe('3300.00');
});

test('a strategy keeps its steps in order and one default', function (): void {
    $steps = [['name' => 'Reminder', 'days_after_due' => 5, 'channel' => 'email'], ['name' => 'Call', 'days_after_due' => 15, 'channel' => 'phone', 'fee_amount' => 0], ['name' => 'Legal', 'days_after_due' => 45, 'channel' => 'legal', 'escalates' => true, 'fee_amount' => 25]];

    $this->postJson('/api/v1/credit-management/dunning-strategies', ['name' => 'Bad', 'steps' => [['name' => 'Late', 'days_after_due' => 20, 'channel' => 'email'], ['name' => 'Early', 'days_after_due' => 10, 'channel' => 'phone']]])->assertUnprocessable()->assertJsonValidationErrors('steps.1.days_after_due');

    $first = $this->postJson('/api/v1/credit-management/dunning-strategies', ['name' => 'Standard', 'steps' => $steps])->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.steps.2.step_order', 3)->json('data');
    $second = $this->postJson('/api/v1/credit-management/dunning-strategies', ['name' => 'Soft', 'is_default' => true, 'steps' => [$steps[0]]])->assertCreated()->json('data');

    $this->getJson('/api/v1/credit-management/dunning-strategies/'.$first['id'])->assertOk()->assertJsonPath('data.is_default', false);
    $this->putJson('/api/v1/credit-management/dunning-strategies/'.$second['id'], ['name' => 'Soft', 'is_default' => false, 'steps' => [$steps[0]]])->assertUnprocessable()->assertJsonValidationErrors('is_default');
    $this->deleteJson('/api/v1/credit-management/dunning-strategies/'.$first['id'])->assertNoContent();
});

function cmStrategy(): array
{
    return test()->postJson('/api/v1/credit-management/dunning-strategies', ['name' => 'Standard', 'steps' => [
        ['name' => 'Reminder', 'days_after_due' => 5, 'channel' => 'email', 'fee_amount' => 0],
        ['name' => 'Call', 'days_after_due' => 20, 'channel' => 'phone'],
        ['name' => 'Legal', 'days_after_due' => 60, 'channel' => 'legal', 'escalates' => true, 'fee_amount' => 40],
    ]])->assertCreated()->json('data');
}

test('a case is opened on overdue invoices and moves through the dunning steps when they are due', function (): void {
    cmStrategy();
    $customer = cmCustomer();
    $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    $invoice = cmInvoice($customer, '2027-06-01', '1000.00');
    cmInvoice($customer, '2027-07-30', '700.00');

    $case = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id, 'priority' => 'high'])->assertCreated()->assertJsonPath('data.case_number', 'CC-2027-0001')->assertJsonPath('data.status', 'open')->assertJsonPath('data.outstanding_amount', '1000.00')->assertJsonPath('data.next_action_on', '2027-06-06')->assertJsonCount(1, 'data.invoices')->json('data');
    $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertUnprocessable()->assertJsonValidationErrors('invoice_ids');

    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertOk()->assertJsonPath('data.status', 'in_dunning')->assertJsonPath('data.current_step_order', 1)->assertJsonPath('data.next_action_on', '2027-06-21');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->travelTo('2027-06-25 09:00:00');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance', ['outcome' => 'Customer not reachable'])->assertOk()->assertJsonPath('data.current_step_order', 2)->assertJsonCount(2, 'data.actions');

    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/actions', ['action_type' => 'note', 'outcome' => 'Spoke with accounting'])->assertCreated();
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/actions', ['action_type' => 'dunning_step'])->assertUnprocessable();

    $this->travelTo('2027-08-05 09:00:00');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertOk()->assertJsonPath('data.status', 'escalated')->assertJsonPath('data.actions.3.fee_amount', '40.00');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertUnprocessable();

    $invoice->update(['total_paid' => '1000.00', 'payment_status' => 'paid']);
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/close', ['outcome' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertUnprocessable();
});

test('a promise to pay holds the case and is kept or broken', function (): void {
    cmStrategy();
    $customer = cmCustomer();
    $invoice = cmInvoice($customer, '2027-06-01', '1000.00');
    $case = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-management/promises', ['case_id' => $case['id'], 'promised_amount' => 1500, 'promised_on' => '2027-06-20'])->assertUnprocessable()->assertJsonValidationErrors('promised_amount');
    $this->postJson('/api/v1/credit-management/promises', ['case_id' => $case['id'], 'promised_amount' => 500, 'promised_on' => '2027-06-01'])->assertUnprocessable()->assertJsonValidationErrors('promised_on');

    $promise = $this->postJson('/api/v1/credit-management/promises', ['case_id' => $case['id'], 'promised_amount' => 1000, 'promised_on' => '2027-06-20'])->assertCreated()->json('data');
    $this->postJson('/api/v1/credit-management/promises', ['case_id' => $case['id'], 'promised_amount' => 100, 'promised_on' => '2027-06-20'])->assertUnprocessable()->assertJsonValidationErrors('case_id');
    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.status', 'promised')->assertJsonPath('data.next_action_on', '2027-06-21');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertUnprocessable();

    $this->postJson('/api/v1/credit-management/promises/'.$promise['id'].'/resolve', ['outcome' => 'kept'])->assertUnprocessable()->assertJsonValidationErrors('paid_amount');
    $this->postJson('/api/v1/credit-management/promises/'.$promise['id'].'/resolve', ['outcome' => 'kept', 'paid_amount' => 400])->assertUnprocessable()->assertJsonValidationErrors('paid_amount');

    $this->travelTo('2027-06-22 09:00:00');
    $this->postJson('/api/v1/credit-management/promises/mark-broken')->assertOk()->assertJsonPath('data.marked', 1);
    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.status', 'open');
    $this->postJson('/api/v1/credit-management/promises/'.$promise['id'].'/resolve', ['outcome' => 'broken'])->assertUnprocessable();

    $again = $this->postJson('/api/v1/credit-management/promises', ['case_id' => $case['id'], 'promised_amount' => 1000, 'promised_on' => '2027-06-30'])->assertCreated()->json('data');
    $invoice->update(['total_paid' => '1000.00', 'payment_status' => 'paid']);
    $this->postJson('/api/v1/credit-management/promises/'.$again['id'].'/resolve', ['outcome' => 'kept', 'paid_amount' => 1000])->assertOk()->assertJsonPath('data.status', 'kept');
    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.outstanding_amount', '0.00');
});

test('a dispute holds the case until it is decided', function (): void {
    cmStrategy();
    $customer = cmCustomer();
    $invoice = cmInvoice($customer, '2027-06-01', '1000.00');
    $case = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-management/disputes', ['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'disputed_amount' => 2000, 'reason_code' => 'pricing'])->assertUnprocessable()->assertJsonValidationErrors('disputed_amount');
    $this->postJson('/api/v1/credit-management/disputes', ['customer_id' => $customer->id, 'invoice_id' => (string) Str::uuid(), 'disputed_amount' => 10, 'reason_code' => 'pricing'])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');

    $dispute = $this->postJson('/api/v1/credit-management/disputes', ['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'disputed_amount' => 300, 'reason_code' => 'quality', 'description' => 'Damaged goods'])->assertCreated()->assertJsonPath('data.case_id', $case['id'])->json('data');
    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.status', 'disputed')->assertJsonCount(1, 'data.disputes');
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertUnprocessable();
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/close', ['outcome' => 'resolved'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/review')->assertOk()->assertJsonPath('data.status', 'under_review');
    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/review')->assertUnprocessable();
    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/resolve', ['outcome' => 'upheld'])->assertUnprocessable()->assertJsonValidationErrors('credited_amount');
    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/resolve', ['outcome' => 'upheld', 'credited_amount' => 500])->assertUnprocessable()->assertJsonValidationErrors('credited_amount');
    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/resolve', ['outcome' => 'upheld', 'credited_amount' => 300, 'resolution_note' => 'Credit note issued'])->assertOk()->assertJsonPath('data.status', 'upheld')->assertJsonPath('data.credited_amount', '300.00');

    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.status', 'open');
    $this->postJson('/api/v1/credit-management/disputes/'.$dispute['id'].'/resolve', ['outcome' => 'rejected'])->assertUnprocessable();
});

test('writing a case off is reserved to approvers', function (): void {
    cmStrategy();
    $customer = cmCustomer();
    cmInvoice($customer, '2027-06-01', '1000.00');
    $case = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertCreated()->json('data');

    cmAct(cmMember($this->tenant, 'accountant'), $this->tenant);
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/close', ['outcome' => 'written_off'])->assertForbidden();
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/close', ['outcome' => 'resolved'])->assertUnprocessable()->assertJsonValidationErrors('outcome');

    cmAct(cmMember($this->tenant, 'accountant', ['credit-management.approve']), $this->tenant);
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/close', ['outcome' => 'written_off', 'note' => 'Bankrupt'])->assertOk()->assertJsonPath('data.status', 'written_off');
    $this->putJson('/api/v1/credit-management/cases/'.$case['id'], ['priority' => 'low'])->assertUnprocessable();
});

test('the worklist puts due actions first and filters by collector', function (): void {
    cmStrategy();
    $collector = cmMember($this->tenant, 'accountant');
    $late = cmCustomer(null, 'Late');
    $soon = cmCustomer(null, 'Soon');
    cmInvoice($late, '2027-05-01', '800.00');
    cmInvoice($soon, '2027-06-12', '5000.00');

    $lateCase = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $late->id, 'collector_id' => $collector->id])->assertCreated()->json('data');
    $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $soon->id, 'priority' => 'high'])->assertCreated();

    $rows = $this->getJson('/api/v1/credit-management/worklist')->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect($rows[0]['id'])->toBe($lateCase['id'])->and($rows[0]['is_action_due'])->toBeTrue()->and($rows[0]['days_overdue'])->toBe(45)->and($rows[1]['is_action_due'])->toBeFalse();

    $this->getJson('/api/v1/credit-management/worklist?due_only=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/credit-management/worklist?collector_id='.$collector->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', $rows[0]['customer_name']);

    cmAct($collector, $this->tenant);
    $this->getJson('/api/v1/credit-management/worklist?mine=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/credit-management/cases/'.$lateCase['id'].'/assign', ['collector_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('collector_id');
    $this->postJson('/api/v1/credit-management/cases/'.$lateCase['id'].'/assign', ['collector_id' => null])->assertOk()->assertJsonPath('data.collector_id', null);
});

test('the dashboard reports DSO, overdue by bucket and the open workload', function (): void {
    cmStrategy();
    cmScorecard();
    $customer = cmCustomer();
    cmInvoice($customer, '2027-05-30', '3000.00', '2027-05-01');
    cmInvoice($customer, '2027-08-01', '1000.00', '2027-06-01');
    $paid = cmInvoice($customer, '2027-04-01', '500.00', '2027-04-01');
    $paid->update(['total_paid' => '500.00', 'payment_status' => 'paid']);
    cmScore($customer, 90)->assertCreated();
    $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $customer->id])->assertCreated();

    $data = $this->getJson('/api/v1/credit-management/dashboard')->assertOk()->json('data');

    expect($data)->toMatchArray(['receivables_total' => '4000.00', 'overdue_total' => '3000.00', 'overdue_percentage' => '75.00', 'invoiced_last_90_days' => '4500.00', 'dso_days' => '80.00', 'customers_over_limit' => 0]);
    expect($data['cases'])->toMatchArray(['open_count' => 1, 'outstanding_amount' => '3000.00'])->and($data['cases']['by_status']['open'])->toBe(1);
    expect(collect($data['overdue_by_bucket'])->pluck('amount', 'key')->all())->toMatchArray(['d1_30' => '3000.00', 'd31_60' => '0.00', 'current' => '1000.00'])->and($data['rating_distribution'])->toBe([['rating' => 'C', 'count' => 1]]);
    expect($data['top_overdue_customers'][0]['overdue_amount'])->toBe('3000.00');
});

test('records stay inside the company', function (): void {
    cmStrategy();
    $foreignTenant = cmTenant('Concorrente');
    $foreign = cmCustomer($foreignTenant, 'Foreign');
    cmInvoice($foreign, '2027-05-01', '900.00');
    cmScorecard();

    cmScore($foreign)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $foreign->id])->assertUnprocessable();
    $this->getJson('/api/v1/credit-management/customers/'.$foreign->id.'/exposure')->assertNotFound();
    expect($this->getJson('/api/v1/credit-management/exposures')->json('data'))->toBe([]);

    $ownCustomer = cmCustomer();
    cmInvoice($ownCustomer, '2027-05-01', '500.00');
    $case = $this->postJson('/api/v1/credit-management/cases', ['customer_id' => $ownCustomer->id])->assertCreated()->json('data');
    $score = cmScore($ownCustomer)->assertCreated()->json('data');

    $foreignOwner = cmMember($foreignTenant, 'owner');
    cmAct($foreignOwner, $foreignTenant);
    $this->getJson('/api/v1/credit-management/cases/'.$case['id'])->assertNotFound();
    $this->getJson('/api/v1/credit-management/scores/'.$score['id'])->assertNotFound();
    $this->postJson('/api/v1/credit-management/cases/'.$case['id'].'/advance')->assertNotFound();
    $this->getJson('/api/v1/credit-management/cases')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/credit-management/scorecard/factors')->assertOk()->assertJsonCount(0, 'data');
    expect(CreditCollectionCase::withoutGlobalScopes()->count())->toBe(1);
});

test('roles read and write according to the module rules', function (): void {
    $customer = cmCustomer();

    cmAct(cmMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/credit-management/dashboard')->assertForbidden();

    cmAct(cmMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/credit-management/dashboard')->assertOk();
    $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id, 'requested_limit' => 100])->assertForbidden();

    cmAct(cmMember($this->tenant, 'sales'), $this->tenant);
    $this->getJson('/api/v1/credit-management/exposures')->assertOk();
    $this->postJson('/api/v1/credit-management/scorecard/defaults')->assertForbidden();

    cmAct(cmMember($this->tenant, 'sales_manager'), $this->tenant);
    $this->postJson('/api/v1/credit-management/limit-requests', ['customer_id' => $customer->id, 'requested_limit' => 100])->assertCreated();

});
