<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Models\Contact;
use App\Models\LegalCase;
use Modules\PracticeManagement\Models\PracticeCaseParty;
use Modules\PracticeManagement\Models\PracticeConflictCheck;

/**
 * Conflicts-of-interest search. The name is looked up among the parties recorded on matters and among the clients of
 * existing matters. The result is "conflict" when the subject would sit opposite one of its own clients (or when a
 * client is proposed that is an opposing party elsewhere), "review" for any other hit, "clear" when nothing matches.
 * A hit is a candidate for human review, never an automatic verdict on a person with a similar name.
 */
final class ConflictCheckService
{
    /**
     * @return array{result: string, matches: list<array<string, mixed>>}
     */
    public function search(string $name, string $role, ?string $contactId): array
    {
        $needle = trim($name);
        $matches = [];

        $parties = PracticeCaseParty::query()
            ->with('legalCase')
            ->where(fn ($query) => $query->whereLike('name', "%{$needle}%")->when($contactId !== null, fn ($inner) => $inner->orWhere('contact_id', $contactId)))
            ->get();

        foreach ($parties as $party) {
            $matches[$party->legal_case_id.'|'.$party->role] = $this->match('party', $party->name, $party->role, $party->legalCase, $party->contact_id);
        }

        $contacts = Contact::query()
            ->where(fn ($query) => $query->whereLike('company_name', "%{$needle}%")->orWhereLike('last_name', "%{$needle}%")->when($contactId !== null, fn ($inner) => $inner->orWhere('id', $contactId)))
            ->pluck('id');

        foreach (LegalCase::query()->whereIn('client_id', $contacts)->with('client')->get() as $case) {
            $client = $case->client;
            $matches[$case->id.'|client'] = $this->match('client', (string) ($client?->company_name ?: trim(($client->first_name ?? '').' '.($client->last_name ?? ''))), 'client', $case, (string) $case->client_id);
        }

        $matches = array_values($matches);
        $opposing = static fn (array $match): bool => ($role === 'client' && $match['role'] === 'opposing_party') || ($role === 'opposing_party' && $match['role'] === 'client');
        $result = $matches === [] ? 'clear' : (array_filter($matches, $opposing) !== [] ? 'conflict' : 'review');

        return ['result' => $result, 'matches' => $matches];
    }

    /**
     * Runs the search and keeps its outcome as evidence.
     */
    public function check(string $name, string $role, ?string $contactId, ?string $userId): PracticeConflictCheck
    {
        $outcome = $this->search($name, $role, $contactId);

        $check = new PracticeConflictCheck;
        $check->fill([
            'subject_name' => trim($name),
            'subject_role' => $role,
            'contact_id' => $contactId,
            'result' => $outcome['result'],
            'matches' => $outcome['matches'],
            'checked_by' => $userId,
        ]);
        $check->save();

        return $check;
    }

    /**
     * @return array<string, mixed>
     */
    private function match(string $source, string $name, string $role, ?LegalCase $case, ?string $contactId): array
    {
        return [
            'source' => $source,
            'name' => $name,
            'role' => $role,
            'contact_id' => $contactId,
            'legal_case_id' => $case?->id,
            'case_number' => $case?->case_number,
            'case_title' => $case?->title,
        ];
    }
}
