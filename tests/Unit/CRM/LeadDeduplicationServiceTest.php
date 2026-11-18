<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadDeduplicationServiceInterface;
use Modules\CRM\DTOs\DuplicateMatch;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadDuplicateCandidate;
use Modules\CRM\Models\LeadSetting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Dedup Tenant', 'legal_name' => 'Dedup Tenant Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->dedup = app(LeadDeduplicationServiceInterface::class);

    $this->makeLead = fn (array $attributes): Lead => Lead::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'status' => 'new',
        'country' => 'IT',
    ], $attributes));

    $this->find = fn (array $identifiers, ?string $exclude = null): array => $this->dedup->findMatches((string) $this->tenant->id, $identifiers, $exclude);
});

test('matches an existing lead by email ignoring case', function (): void {
    $existing = ($this->makeLead)(['email' => 'anna@verdi.it']);

    $matches = ($this->find)(['email' => ' Anna@Verdi.IT ']);

    expect($matches)->toHaveCount(1)
        ->and($matches[0])->toBeInstanceOf(DuplicateMatch::class)
        ->and($matches[0]->recordType)->toBe('lead')
        ->and($matches[0]->recordId)->toBe((string) $existing->id)
        ->and($matches[0]->matchType)->toBe('email')
        ->and($matches[0]->isExact())->toBeTrue()
        ->and($matches[0]->confidence)->toBe(1.0);
});

test('matches by E.164 phone whatever the input format', function (): void {
    $existing = ($this->makeLead)(['phone' => '333 123 4567']);

    expect($existing->fresh()->phone_e164)->toBe('+393331234567');

    $matches = ($this->find)(['phone' => '+39 333-1234567']);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->recordId)->toBe((string) $existing->id)
        ->and($matches[0]->matchType)->toBe('phone');
});

test('matches by VAT with or without the IT prefix', function (): void {
    $existing = ($this->makeLead)(['vat_number' => 'it 123 456 789 01']);

    expect($existing->fresh()->vat_number)->toBe('IT12345678901');

    $matches = ($this->find)(['vat_number' => '12345678901']);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->matchType)->toBe('vat');
});

test('finds fuzzy company matches within the configured distance and never as exact', function (): void {
    $similar = ($this->makeLead)(['company_name' => 'Precision Engineering S.p.A.']);
    ($this->makeLead)(['company_name' => 'Completely Different Business Srl']);

    $matches = ($this->find)(['company_name' => 'Precisione Engineering SRL']);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->recordId)->toBe((string) $similar->id)
        ->and($matches[0]->matchType)->toBe('company_fuzzy')
        ->and($matches[0]->isExact())->toBeFalse()
        ->and($matches[0]->confidence)->toBeGreaterThanOrEqual(0.85);
});

test('respects the tenant levenshtein and similarity settings', function (): void {
    ($this->makeLead)(['company_name' => 'Alpha Logistics']);
    LeadSetting::create(['tenant_id' => (string) $this->tenant->id, 'dedup_levenshtein_max' => 0, 'dedup_similarity_min' => 0.99]);

    expect(($this->find)(['company_name' => 'Alpha Logistic']))->toBe([]);
});

test('ignores converted and merged leads and the excluded lead', function (): void {
    ($this->makeLead)(['email' => 'same@acme.ch', 'status' => 'converted']);
    $survivor = ($this->makeLead)(['email' => 'other@acme.ch']);
    ($this->makeLead)(['email' => 'same@acme.ch', 'merged_into_lead_id' => $survivor->id]);
    $self = ($this->makeLead)(['email' => 'same@acme.ch']);

    expect(($this->find)(['email' => 'same@acme.ch'], (string) $self->id))->toBe([]);
});

test('matches contacts and their accounts by VAT and email', function (): void {
    $contact = Contact::create([
        'tenant_id' => (string) $this->tenant->id,
        'type' => 'client',
        'country' => 'CH',
        'company_name' => 'Ticino Trading SA',
        'email' => 'info@ticino-trading.ch',
        'vat_number' => 'CHE-123.456.789 MWST',
    ]);
    $account = Account::create([
        'tenant_id' => (string) $this->tenant->id,
        'account_number' => 'ACC-DEDUP-1',
        'name' => 'Ticino Trading SA',
        'contact_id' => $contact->id,
    ]);

    $matches = collect(($this->find)(['vat_number' => 'CHE123456789']));

    expect($matches->firstWhere('recordType', 'contact')?->recordId)->toBe((string) $contact->id)
        ->and($matches->firstWhere('recordType', 'contact')?->matchType)->toBe('vat')
        ->and($matches->firstWhere('recordType', 'account')?->recordId)->toBe((string) $account->id);

    $byEmail = collect(($this->find)(['email' => 'INFO@ticino-trading.ch']));
    expect($byEmail->firstWhere('recordType', 'contact')?->matchType)->toBe('email');
});

test('records each candidate once and resolves contacts and accounts for conversion', function (): void {
    $existing = ($this->makeLead)(['email' => 'luca@neri.it', 'company_name' => 'Neri Srl']);
    $incoming = ($this->makeLead)(['email' => 'luca@neri.it', 'company_name' => 'Neri Srl', 'vat_number' => 'IT01234567890']);

    $matches = $this->dedup->findMatchesForLead($incoming);
    $first = $this->dedup->recordCandidates($incoming, $matches);
    $second = $this->dedup->recordCandidates($incoming, $matches);

    expect($first)->toHaveCount(1)
        ->and($second)->toBe([])
        ->and(LeadDuplicateCandidate::where('lead_id', $incoming->id)->where('candidate_lead_id', $existing->id)->count())->toBe(1);

    $contact = Contact::create(['tenant_id' => (string) $this->tenant->id, 'type' => 'client', 'country' => 'IT', 'company_name' => 'Neri', 'vat_number' => '01234567890']);
    $account = Account::create(['tenant_id' => (string) $this->tenant->id, 'account_number' => 'ACC-'.Str::random(5), 'name' => 'Neri S.r.l.']);

    expect($this->dedup->findContactForLead($incoming)?->id)->toBe($contact->id)
        ->and($this->dedup->findAccountForLead($incoming)?->id)->toBe($account->id);
});

test('compares normalized company names', function (): void {
    [$distance, $similarity] = $this->dedup->compareCompanyNames('rossi costruzioni', 'rosi costruzioni');

    expect($distance)->toBe(1)
        ->and($similarity)->toBeGreaterThan(0.9)
        ->and($this->dedup->compareCompanyNames('acme', 'acme'))->toBe([0, 1.0]);
});
