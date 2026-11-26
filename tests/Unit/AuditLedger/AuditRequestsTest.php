<?php

declare(strict_types=1);

use App\Http\Requests\Audit\ExportAuditRequest;
use App\Http\Requests\Audit\SearchAuditEntriesRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Runs the rules and the window check of a request without authorising it (the policy has its own tests).
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<string, mixed>  $data
 */
function auditRequestErrors(string $class, array $data): array
{
    /** @var FormRequest $request */
    $request = $class::create('/api/v1/settings/audit-logs', 'GET', $data);
    $request->setContainer(app());
    $validator = Validator::make($request->all(), $request->rules());

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    return $validator->errors()->toArray();
}

test('a valid search passes with every filter', function (): void {
    $errors = auditRequestErrors(SearchAuditEntriesRequest::class, [
        'from' => '2027-01-01', 'to' => '2027-03-31', 'actor' => 'abc', 'action' => 'updated', 'resource_type' => 'Invoice',
        'company_id' => '0194f7a0-1111-7000-8000-000000000001', 'channel' => 'api', 'ip' => '203.0.113.9', 'category' => 'crud', 'limit' => 50,
    ]);

    expect($errors)->toBe([]);
});

test('dates must be written as Y-m-d', function (string $value): void {
    expect(auditRequestErrors(SearchAuditEntriesRequest::class, ['from' => $value]))->toHaveKey('from');
})->with(['01/02/2027', '2027-1-2', '2027-01-02T10:00:00Z', 'yesterday']);

test('the end of the range cannot precede its start', function (): void {
    expect(auditRequestErrors(SearchAuditEntriesRequest::class, ['from' => '2027-02-01', 'to' => '2027-01-01']))->toHaveKey('to');
});

test('a window of 366 days is accepted and 367 is refused', function (): void {
    expect(auditRequestErrors(SearchAuditEntriesRequest::class, ['from' => '2027-01-01', 'to' => '2028-01-02']))->toBe([])
        ->and(auditRequestErrors(SearchAuditEntriesRequest::class, ['from' => '2027-01-01', 'to' => '2028-01-03']))->toHaveKey('to');
});

test('the channel, the category, the ip and the limit are validated', function (): void {
    $errors = auditRequestErrors(SearchAuditEntriesRequest::class, ['channel' => 'fax', 'category' => 'misc', 'ip' => 'not-an-ip', 'limit' => 500, 'company_id' => 'nope']);

    expect($errors)->toHaveKeys(['channel', 'category', 'ip', 'limit', 'company_id']);
});

test('an export needs a window of at most 366 days', function (): void {
    expect(auditRequestErrors(ExportAuditRequest::class, []))->toHaveKeys(['from', 'to'])
        ->and(auditRequestErrors(ExportAuditRequest::class, ['from' => '2027-01-01', 'to' => '2027-12-31']))->toBe([])
        ->and(auditRequestErrors(ExportAuditRequest::class, ['from' => '2026-01-01', 'to' => '2027-12-31']))->toHaveKey('to');
});

test('the idempotency key of an export is bounded', function (): void {
    expect(auditRequestErrors(ExportAuditRequest::class, ['from' => '2027-01-01', 'to' => '2027-01-31', 'idempotency_key' => str_repeat('k', 121)]))->toHaveKey('idempotency_key')
        ->and(auditRequestErrors(ExportAuditRequest::class, ['from' => '2027-01-01', 'to' => '2027-01-31', 'idempotency_key' => 'abc-123']))->toBe([]);
});

test('the filters of a request are the non empty ones', function (): void {
    $request = SearchAuditEntriesRequest::create('/', 'GET', ['from' => '2027-01-01', 'action' => '', 'channel' => 'web', 'limit' => 10]);

    expect($request->filters())->toBe(['from' => '2027-01-01', 'channel' => 'web']);
});
