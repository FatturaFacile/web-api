<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAgendaItem;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\ProtocolEntry;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    config(['filesystems.private_disk' => 'private']);
});

afterEach(fn () => associationsTearDown());

test('the members book is generated from the registry and keeps every year', function (): void {
    $service = app(MembershipService::class);
    $type = MembershipType::factory()->create(['name' => 'Ordinary']);
    $last = AssociativeYear::factory()->create(['starts_on' => now()->subYear()->startOfYear(), 'ends_on' => now()->subYear()->endOfYear()]);
    $current = AssociativeYear::factory()->create();
    $member = Member::factory()->create(['health_notes' => 'never in a book']);
    $service->activate($service->request($member, $last, $type));
    $service->activate($service->request($member, $current, $type));

    actAs($this, 'accountant');
    $book = $this->getJson('/api/v1/associations/books/members')->assertOk()->json('data');

    expect($book['rows'])->toHaveCount(2)
        ->and($book['rows'][0][1])->toBe($member->contact->last_name)
        ->and(json_encode($book))->not->toContain('never in a book');

    $this->getJson('/api/v1/associations/books/members?year='.now()->year)->assertJsonCount(1, 'data.rows');

    $csv = (string) $this->get('/api/v1/associations/books/members/csv')->assertOk()->getContent();
    expect($csv)->toContain($member->contact->last_name);
    expect($this->get('/api/v1/associations/books/members/pdf')->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
});

test('the volunteers register lists regular volunteers active in the year with their insurance', function (): void {
    $regular = Volunteer::factory()->create(['activity' => 'Ambulance driver']);
    Volunteer::factory()->occasional()->create();
    Volunteer::factory()->create(['started_on' => now()->subYears(3)->toDateString(), 'ended_on' => now()->subYears(2)->toDateString()]);

    actAs($this, 'secretary');
    $book = $this->getJson('/api/v1/associations/books/volunteers?year='.now()->year)->assertOk()->json('data');

    expect($book['rows'])->toHaveCount(1)
        ->and($book['rows'][0][3])->toBe('Ambulance driver')
        ->and($book['rows'][0][8])->toBe($regular->insurance_policy);

    $this->getJson('/api/v1/associations/volunteers/year/'.now()->year)->assertOk()->assertJsonPath('meta.regular', 1)->assertJsonPath('meta.occasional', 1);
});

test('the protocol numbers correspondence per year without gaps and never deletes an entry', function (): void {
    actAs($this, 'secretary');

    $first = $this->post('/api/v1/associations/protocol', [
        'direction' => 'incoming', 'correspondent' => 'Municipality', 'subject' => 'Grant notice', 'registered_on' => now()->toDateString(),
        'file' => UploadedFile::fake()->create('notice.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/associations/protocol', ['direction' => 'outgoing', 'correspondent' => 'Federation', 'subject' => 'Affiliation renewal'])->assertCreated()->json('data');
    $nextYear = $this->postJson('/api/v1/associations/protocol', ['direction' => 'internal', 'correspondent' => 'Board', 'subject' => 'Note', 'registered_on' => now()->addYear()->startOfYear()->toDateString()])->json('data');

    expect($first['number'])->toBe(sprintf('PROT%d-00001', now()->year))
        ->and($second['number'])->toBe(sprintf('PROT%d-00002', now()->year))
        ->and($nextYear['number'])->toBe(sprintf('PROT%d-00001', now()->year + 1))
        ->and($first)->not->toHaveKey('path');

    $this->postJson("/api/v1/associations/protocol/{$second['id']}/cancel", ['reason' => 'Registered twice'])->assertOk();
    $this->postJson("/api/v1/associations/protocol/{$second['id']}/cancel", ['reason' => 'Again'])->assertConflict();
    $this->get("/api/v1/associations/protocol/{$first['id']}/download")->assertOk();

    expect(ProtocolEntry::query()->count())->toBe(3);
    $this->getJson('/api/v1/associations/books/protocol?year='.now()->year)->assertJsonCount(2, 'data.rows');
});

test('the archive finds meetings by agenda text and participant, and protocol entries by subject', function (): void {
    $meeting = Meeting::factory()->create(['status' => MeetingStatus::Convened, 'title' => 'Spring assembly']);
    MeetingAgendaItem::query()->create(['meeting_id' => $meeting->id, 'position' => 1, 'title' => 'New gym lease']);
    Meeting::factory()->create(['status' => MeetingStatus::Convened, 'title' => 'Autumn assembly']);

    actAs($this, 'secretary');
    $this->postJson('/api/v1/associations/protocol', ['direction' => 'incoming', 'correspondent' => 'Landlord', 'subject' => 'Gym lease draft'])->assertCreated();

    $results = $this->getJson('/api/v1/associations/archive?q=lease')->assertOk()->json('data');

    expect(collect($results)->pluck('type')->sort()->values()->all())->toBe(['meeting', 'protocol'])
        ->and(collect($results)->firstWhere('type', 'meeting')['id'])->toBe($meeting->id);

    $this->getJson('/api/v1/associations/archive?body=board')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/associations/books/meetings')->assertOk()->assertJsonCount(2, 'data.rows');
});

test('books and protocol of another company are not reachable', function (): void {
    $foreign = inTenant($this->otherTenant, function (): ProtocolEntry {
        actAs($this, 'admin', tenant: $this->otherTenant);
        $id = $this->postJson('/api/v1/associations/protocol', ['direction' => 'incoming', 'correspondent' => 'X', 'subject' => 'Foreign'])->json('data.id');

        return ProtocolEntry::query()->findOrFail($id);
    });

    actAs($this, 'admin');
    $this->getJson('/api/v1/associations/books/protocol')->assertJsonCount(0, 'data.rows');
    $this->postJson("/api/v1/associations/protocol/{$foreign->id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->getJson('/api/v1/associations/archive?q=Foreign')->assertJsonCount(0, 'data');
});

test('an accountant reads the books but cannot write the protocol', function (): void {
    actAs($this, 'accountant');

    $this->getJson('/api/v1/associations/books/meetings')->assertOk();
    $this->postJson('/api/v1/associations/protocol', ['direction' => 'incoming', 'correspondent' => 'X', 'subject' => 'Y'])->assertForbidden();
});
